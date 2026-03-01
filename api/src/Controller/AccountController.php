<?php

declare(strict_types=1);

namespace App\Controller;

use App\Account\AccountService;
use League\OAuth2\Client\Provider\Google;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/api/account')]
final class AccountController extends AbstractController
{
    public function __construct(
        private AccountService $accounts,
        #[Autowire('%env(GOOGLE_CLIENT_ID)%')] private string $clientId,
        #[Autowire('%env(GOOGLE_CLIENT_SECRET)%')] private string $clientSecret,
        #[Autowire('%env(GOOGLE_REDIRECT_URI)%')] private string $redirectUri,
        #[Autowire('%kernel.environment%')] private string $environment,
    ) {}

    #[Route('/google/start', methods: ['GET'])]
    public function start(Request $request): RedirectResponse
    {
        $locale = $this->locale($request->query->getString('locale'));
        if ($this->clientId === '' || $this->clientSecret === '') {
            return new RedirectResponse('/'.$locale.'/account?error=google-setup');
        }
        $provider = $this->provider();
        $url = $provider->getAuthorizationUrl();
        $request->getSession()->set('google_oauth_state', $provider->getState());
        $request->getSession()->set('google_oauth_locale', $locale);

        return new RedirectResponse($url);
    }

    #[Route('/google/callback', methods: ['GET'])]
    public function callback(Request $request): RedirectResponse
    {
        $locale = $this->locale((string) $request->getSession()->get('google_oauth_locale', 'cs'));
        $expected = $request->getSession()->remove('google_oauth_state');
        $request->getSession()->remove('google_oauth_locale');
        $state = $request->query->getString('state');
        if (!is_string($expected) || $expected === '' || !hash_equals($expected, $state) || $request->query->has('error')) {
            return new RedirectResponse('/'.$locale.'/account?error=google-state');
        }
        try {
            $token = $this->provider()->getAccessToken('authorization_code', ['code' => $request->query->getString('code')]);
            $owner = $this->provider()->getResourceOwner($token);
            if ($owner->getEmailVerified() !== true || !filter_var($owner->getEmail(), FILTER_VALIDATE_EMAIL)) {
                return new RedirectResponse('/'.$locale.'/account?error=google-email');
            }
            $this->accounts->register($request, (string) $owner->getId(), $owner->getEmail(), $owner->getName(), $locale);

            return new RedirectResponse('/'.$locale.'/account');
        } catch (\Throwable) {
            return new RedirectResponse('/'.$locale.'/account?error=google-login');
        }
    }

    #[Route('/demo-login', methods: ['POST'])]
    public function demo(Request $request): JsonResponse
    {
        if ($this->environment !== 'dev') {
            return $this->json(['message' => 'Demo sign-in is unavailable'], 404);
        }
        $request->getSession()->start();
        $sub = 'demo:'.$request->getSession()->getId();
        $this->accounts->register($request, $sub, 'demo@nodra.test', 'NODRA Demo Rider', $this->locale($request->query->getString('locale')));

        return $this->json($this->accounts->summary($this->accounts->current($request)));
    }

    #[Route('/me', methods: ['GET'])]
    public function me(Request $request): JsonResponse
    {
        $account = $this->accounts->current($request);

        return $account === null ? $this->json(['message' => 'Sign in required'], 401) : $this->json($this->accounts->summary($account));
    }

    #[Route('/logout', methods: ['POST'])]
    public function logout(Request $request): JsonResponse
    {
        $request->getSession()->remove('customer_account_id');

        return $this->json(['ok' => true]);
    }

    private function provider(): Google
    {
        return new Google(['clientId' => $this->clientId, 'clientSecret' => $this->clientSecret, 'redirectUri' => $this->redirectUri]);
    }

    private function locale(string $locale): string
    {
        return in_array($locale, ['cs', 'de', 'en'], true) ? $locale : 'cs';
    }
}
