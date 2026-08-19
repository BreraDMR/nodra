<?php

declare(strict_types=1);

namespace App\Installation;

use App\Admin\InstallationsQuery;
use App\Entity\InstallationBooking;
use App\Entity\ShopOrder;
use App\Order\OrderJournal;
use App\Order\OrderProblem;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\DriverException;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Evening installation bookings (D08.1–D08.2). A booking is a record beside the order; it never touches lines,
 * stock or the ledger. Two windows live on it: the preliminary one agreed with the customer and, after confirm,
 * the work window that was actually fixed. On one Prague evening the open bookings stay under the count limit and
 * far enough apart for the road between addresses; every wall-clock check is Prague, DST included. The capacity
 * check and its write run as one step under a per-evening lock, like the order row lock does for payments.
 */
final class InstallationService
{
    /** How long a writer waits for the evening lock before the evening answers as busy */
    private const LOCK_TIMEOUT = '2s';

    private \DateTimeZone $prague;

    public function __construct(
        private EntityManagerInterface $em,
        private Connection $db,
        private OrderJournal $journal,
        private InstallationSettings $settings,
        private ClockInterface $clock,
    ) {
        $this->prague = new \DateTimeZone(InstallationSettings::TIMEZONE);
    }

    public function open(string $orderId, string $from, string $to, array $works, ?string $note, string $actor): array
    {
        $order = $this->em->find(ShopOrder::class, Uuid::fromString(strtolower($orderId)));
        if ($order === null) {
            throw OrderProblem::notFound('Order not found');
        }
        [$windowFrom, $windowTo] = $this->window($from, $to);
        $codes = $this->works($works);

        return $this->withEveningLock($windowFrom, function () use ($order, $windowFrom, $windowTo, $codes, $note, $actor): array {
            $booking = new InstallationBooking($order, $windowFrom, $windowTo, $codes, $this->settings->priceMinor($codes), $note, $actor);
            $this->assertEveningHasRoom($booking, $windowFrom, $windowTo);
            $this->em->persist($booking);
            $this->journal->record($order, 'installation_booked', [
                'installationId' => $booking->getId()->toRfc4122(), 'from' => $windowFrom->format(\DATE_ATOM),
                'to' => $windowTo->format(\DATE_ATOM), 'works' => $codes,
            ], $actor);
            $this->em->flush();

            return InstallationPresenter::present($booking, $this->settings);
        });
    }

    public function show(string $bookingId): array
    {
        return InstallationPresenter::present($this->booking($bookingId), $this->settings);
    }

    public function reschedule(string $bookingId, string $from, string $to, string $actor): array
    {
        $booking = $this->booking($bookingId);
        [$windowFrom, $windowTo] = $this->window($from, $to);

        return $this->withEveningLock($windowFrom, function () use ($booking, $windowFrom, $windowTo, $actor): array {
            if ($booking->getStatus() === InstallationBooking::PLANNED) {
                $booking->reschedulePlanned($windowFrom, $windowTo);
            } elseif ($booking->getStatus() === InstallationBooking::CONFIRMED) {
                $booking->rescheduleWork($windowFrom, $windowTo);
            } else {
                throw new \DomainException(sprintf('A booking that is %s cannot be rescheduled', $booking->getStatus()));
            }
            $this->assertEveningHasRoom($booking, $windowFrom, $windowTo);
            $this->journal->record($booking->getOrder(), 'installation_rescheduled', [
                'installationId' => $booking->getId()->toRfc4122(), 'from' => $windowFrom->format(\DATE_ATOM),
                'to' => $windowTo->format(\DATE_ATOM),
            ], $actor);
            $this->em->flush();

            return InstallationPresenter::present($booking, $this->settings);
        });
    }

    public function confirm(string $bookingId, string $compatibilityNote, ?string $workFrom, ?string $workTo, string $actor): array
    {
        $booking = $this->booking($bookingId);
        if ($workFrom !== null || $workTo !== null) {
            if ($workFrom === null || $workTo === null) {
                throw OrderProblem::unprocessable('bad_window', 'The work window needs both bounds');
            }
            [$from, $to] = $this->window($workFrom, $workTo);
        } else {
            [$from, $to] = [$booking->getPlannedFrom(), $booking->getPlannedTo()];
        }

        return $this->withEveningLock($from, function () use ($booking, $from, $to, $compatibilityNote, $actor): array {
            $booking->confirm($from, $to, $compatibilityNote);
            $this->assertEveningHasRoom($booking, $from, $to);
            $this->journal->record($booking->getOrder(), 'installation_confirmed', [
                'installationId' => $booking->getId()->toRfc4122(), 'from' => $from->format(\DATE_ATOM), 'to' => $to->format(\DATE_ATOM),
            ], $actor);
            $this->em->flush();

            return InstallationPresenter::present($booking, $this->settings);
        });
    }

    public function complete(string $bookingId, string $resultNote, string $actor): array
    {
        $booking = $this->booking($bookingId);
        $booking->complete($resultNote);
        $this->journal->record($booking->getOrder(), 'installation_completed', ['installationId' => $booking->getId()->toRfc4122()], $actor);
        $this->em->flush();

        return InstallationPresenter::present($booking, $this->settings);
    }

    public function cancel(string $bookingId, string $reason, string $actor): array
    {
        $booking = $this->booking($bookingId);
        $booking->cancel($reason);
        $this->journal->record($booking->getOrder(), 'installation_cancelled', ['installationId' => $booking->getId()->toRfc4122(), 'reason' => $reason], $actor);
        $this->em->flush();

        return InstallationPresenter::present($booking, $this->settings);
    }

    public function list(InstallationsQuery $query): array
    {
        $qb = $this->em->createQueryBuilder()
            ->select('booking', 'orders')
            ->from(InstallationBooking::class, 'booking')
            ->join('booking.order', 'orders')
            ->orderBy('booking.createdAt', 'DESC')
            ->addOrderBy('booking.id', 'DESC');
        if ($query->status !== null) {
            $qb->andWhere('booking.status = :status')->setParameter('status', $query->status);
        }
        if ($query->date !== null) {
            [$dayStart, $dayEnd] = $this->dayBounds($query->date);
            // the evening a booking holds is the one its window in force falls on
            $qb->andWhere('COALESCE(booking.workFrom, booking.plannedFrom) >= :dayStart AND COALESCE(booking.workFrom, booking.plannedFrom) < :dayEnd')
                ->setParameter('dayStart', $dayStart)->setParameter('dayEnd', $dayEnd);
        }
        $total = (int) (clone $qb)->select('COUNT(booking.id)')->resetDQLPart('orderBy')->getQuery()->getSingleScalarResult();
        $pages = max(1, (int) ceil($total / 30));
        $page = min($query->page, $pages);
        $bookings = $qb->setMaxResults(30)->setFirstResult(($page - 1) * 30)->getQuery()->getResult();

        return [
            'items' => array_map(fn (InstallationBooking $booking): array => InstallationPresenter::present($booking, $this->settings), $bookings),
            'page' => $page,
            'pages' => $pages,
            'total' => $total,
        ];
    }

    /** A window must be one Prague evening, inside the evening hours; both bounds are ISO instants. */
    private function window(string $from, string $to): array
    {
        $windowFrom = $this->instant($from, 'from');
        $windowTo = $this->instant($to, 'to');
        if ($windowTo <= $windowFrom) {
            throw OrderProblem::unprocessable('bad_window', 'The window must end after it starts');
        }
        $fromPrague = $windowFrom->setTimezone($this->prague);
        $toPrague = $windowTo->setTimezone($this->prague);
        if ($fromPrague->format('Y-m-d') !== $toPrague->format('Y-m-d')) {
            throw OrderProblem::unprocessable('bad_window', 'The window must stay on one Prague evening');
        }
        $eveningStart = $fromPrague->setTime($this->settings->eveningStart, 0);
        $eveningEnd = $fromPrague->setTime($this->settings->eveningEnd, 0);
        if ($windowFrom < $eveningStart || $windowTo > $eveningEnd) {
            throw OrderProblem::unprocessable('bad_window', sprintf(
                'The window must stay between %02d:00 and %02d:00 Prague time',
                $this->settings->eveningStart, $this->settings->eveningEnd,
            ));
        }

        return [$windowFrom, $windowTo];
    }

    private function instant(string $value, string $field): \DateTimeImmutable
    {
        try {
            return new \DateTimeImmutable($value);
        } catch (\Exception) {
            throw OrderProblem::unprocessable('bad_window', sprintf('The %s bound is not a valid instant', $field));
        }
    }

    /** [start, end) instants of the whole Prague calendar day of a YYYY-MM-DD date. */
    private function dayBounds(string $date): array
    {
        $day = \DateTimeImmutable::createFromFormat('!Y-m-d', $date, $this->prague);
        if ($day === false) {
            throw OrderProblem::unprocessable('bad_window', 'The date must be a YYYY-MM-DD Prague evening');
        }
        $dayStart = $day->setTime(0, 0);

        return [$dayStart, $dayStart->modify('+1 day')];
    }

    /** The advisory lock key that serializes the writers of one Prague evening; the tests hold it to play a second admin. */
    public static function eveningLockKey(string $pragueDay): string
    {
        return 'installation-evening-'.$pragueDay;
    }

    /**
     * The evening capacity check and its write run as one step: the lock keeps two admins from passing the check
     * at the same time and double-booking the evening. A writer that cannot get the lock in time gets a conflict
     * instead of a hung request or a raw database error.
     *
     * @param callable(): array $write
     */
    private function withEveningLock(\DateTimeImmutable $windowFrom, callable $write): array
    {
        $key = self::eveningLockKey($windowFrom->setTimezone($this->prague)->format('Y-m-d'));
        try {
            return $this->db->transactional(function () use ($key, $write): array {
                // transaction-scoped, so the timeout never leaks past the transaction that takes the lock
                $this->db->fetchOne("SELECT set_config('lock_timeout', :timeout, true)", ['timeout' => self::LOCK_TIMEOUT]);
                $this->db->fetchOne('SELECT pg_advisory_xact_lock(hashtextextended(:key, 0))', ['key' => $key]);

                return $write();
            });
        } catch (DriverException $e) {
            if ($e->getSQLState() === '55P03') {
                throw OrderProblem::conflict('evening_busy', 'Another booking on this evening is being written right now; try again');
            }
            throw $e;
        }
    }

    /**
     * The evening capacity: open bookings on the same Prague evening stay under the count limit, and two windows
     * keep at least travelMinutes apart, so the installer is never in two places and can drive over.
     */
    private function assertEveningHasRoom(InstallationBooking $changed, \DateTimeImmutable $windowFrom, \DateTimeImmutable $windowTo): void
    {
        $eveningDate = $windowFrom->setTimezone($this->prague)->format('Y-m-d');
        $others = array_filter(
            $this->em->getRepository(InstallationBooking::class)->findBy(['status' => InstallationBooking::OPEN_STATUSES]),
            fn (InstallationBooking $booking): bool => !$booking->getId()->equals($changed->getId())
                && $booking->windowFrom()->setTimezone($this->prague)->format('Y-m-d') === $eveningDate,
        );
        if (count($others) + 1 > $this->settings->maxPerEvening) {
            throw OrderProblem::conflict('evening_full', sprintf('This evening already holds %d open bookings', count($others)));
        }
        $travel = $this->settings->travelMinutes * 60;
        foreach ($others as $other) {
            $gapAfter = $windowFrom->getTimestamp() - $other->windowTo()->getTimestamp();
            $gapBefore = $other->windowFrom()->getTimestamp() - $windowTo->getTimestamp();
            if ($gapAfter < $travel && $gapBefore < $travel) {
                throw OrderProblem::conflict('window_taken', sprintf(
                    'Another window this evening runs %s–%s; two windows need %d minutes apart for the road',
                    $other->windowFrom()->setTimezone($this->prague)->format('H:i'),
                    $other->windowTo()->setTimezone($this->prague)->format('H:i'),
                    $this->settings->travelMinutes,
                ));
            }
        }
    }

    /**
     * @param list<string> $works
     * @return list<string>
     */
    private function works(array $works): array
    {
        $codes = [];
        foreach ($works as $code) {
            if (!is_string($code) || $this->settings->work($code) === null) {
                throw OrderProblem::unprocessable('unknown_work', 'The works list does not know this code');
            }
            $codes[] = $code;
        }

        return $codes;
    }

    private function booking(string $bookingId): InstallationBooking
    {
        try {
            $uuid = Uuid::fromString(strtolower($bookingId));
        } catch (\InvalidArgumentException) {
            throw OrderProblem::notFound('Installation booking not found');
        }
        $booking = $this->em->find(InstallationBooking::class, $uuid);
        if ($booking === null) {
            throw OrderProblem::notFound('Installation booking not found');
        }

        return $booking;
    }
}
