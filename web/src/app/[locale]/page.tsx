import Image from "next/image";
import Link from "next/link";
import { notFound } from "next/navigation";
import { ProductCard } from "@/components/ProductCard";
import { contactLinks, hasMessengers } from "@/lib/contacts";
import { api, copy, isLocale, type Card, type CategoryNode } from "@/lib/shop";
export default async function Landing({
  params,
}: {
  params: Promise<{ locale: string }>;
}) {
  const { locale } = await params;
  if (!isLocale(locale)) notFound();
  const t = copy[locale];
  // the shop blocks of the home page (D06.1): catalog, advice, Prague delivery,
  // Anděl pickup and the installation that is still on its way
  const h = t.home;
  const [{ items }, categories] = await Promise.all([
    api<{ items: Card[] }>(`/api/products?locale=${locale}`),
    api<CategoryNode[]>(`/api/categories?locale=${locale}`),
  ]);
  // contact channels come from the config only; nothing is promised without them (D00.3)
  const channels: { label: string; href: string }[] = [];
  if (contactLinks.whatsapp)
    channels.push({ label: "WhatsApp", href: contactLinks.whatsapp });
  if (contactLinks.telegram)
    channels.push({ label: "Telegram", href: contactLinks.telegram });
  if (contactLinks.email)
    channels.push({
      label: contactLinks.email.label,
      href: contactLinks.email.href,
    });
  return (
    <main>
      <section className="hero">
        <Image
          src="/images/hero-prague.png"
          alt={t.heroImageAlt}
          fill
          priority
          sizes="100vw"
        />
        <div className="hero-shade" />
        <div className="hero-content">
          <p className="eyebrow light">{t.heroEyebrow} / N° 01</p>
          <h1>{t.heroTitle}</h1>
          <p>{t.heroText}</p>
          <Link href={`/${locale}/shop`} className="button button-light">
            {t.explore}
            <span>↗</span>
          </Link>
        </div>
        <div className="hero-bottom">
          <span>01 / 04</span>
          <span>{t.heroTagline}</span>
        </div>
      </section>
      <section className="home-intro">
        <div className="eyebrow">{t.introLabel}</div>
        <p>{t.promise}</p>
        <span>↓</span>
      </section>
      <section className="section-shell">
        <div className="section-heading">
          <div>
            <p className="eyebrow">{h.catalogLabel}</p>
            <h2>{h.catalogTitle}</h2>
            <p>{h.catalogText}</p>
          </div>
          <Link href={`/${locale}/shop`} className="text-link">
            {h.catalogAll} ↗
          </Link>
        </div>
        <div className="catalog-tiles">
          {categories.map((category) => (
            <Link
              key={category.slug}
              href={`/${locale}/shop?category=${encodeURIComponent(category.slug)}`}
              className="catalog-tile"
            >
              <span className="catalog-tile-head">
                <strong>{category.name}</strong>
                <sup>{category.count}</sup>
              </span>
              {category.children.length > 0 && (
                <small>
                  {category.children.map((child) => child.name).join(" · ")}
                </small>
              )}
            </Link>
          ))}
        </div>
      </section>
      <section className="section-shell">
        <div className="section-heading">
          <div>
            <p className="eyebrow">{t.featuredLabel}</p>
            <h2>{t.featured}</h2>
            <p>{t.featuredText}</p>
          </div>
          <Link href={`/${locale}/shop`} className="text-link">
            {t.shop} ↗
          </Link>
        </div>
        <div className="product-grid">
          {items.slice(0, 4).map((product, index) => (
            <ProductCard
              key={product.id}
              product={product}
              locale={locale}
              priority={index === 0}
            />
          ))}
        </div>
      </section>
      <section className="section-shell">
        <div className="service-grid">
          <article className="service-card">
            <p className="eyebrow">{h.consultLabel}</p>
            <h3>{h.consultTitle}</h3>
            <p>{h.consultText}</p>
            {hasMessengers && channels.length > 0 ? (
              <p className="service-links">
                {channels.map((channel, index) => (
                  <span key={channel.href}>
                    {index > 0 && " · "}
                    <a href={channel.href}>{channel.label}</a>
                  </span>
                ))}
              </p>
            ) : (
              <p className="service-links">{h.consultPending}</p>
            )}
          </article>
          <article className="service-card">
            <p className="eyebrow">{h.deliveryLabel}</p>
            <h3>{h.deliveryTitle}</h3>
            <p>{h.deliveryText}</p>
            <p className="service-links">{h.deliveryPayment}</p>
          </article>
          <article className="service-card">
            <p className="eyebrow">{h.pickupLabel}</p>
            <h3>{h.pickupTitle}</h3>
            <p>{h.pickupText}</p>
          </article>
          <article className="service-card service-soon">
            <p className="eyebrow">{h.installLabel}</p>
            <h3>{h.installTitle}</h3>
            <p>{h.installText}</p>
          </article>
        </div>
      </section>
      <section id="story" className="story-block">
        <div className="story-image">
          <Image
            src="/images/frame-bag.png"
            alt={t.storyImageAlt}
            fill
            sizes="50vw"
          />
        </div>
        <div className="story-copy">
          <p className="eyebrow">{t.storyLabel}</p>
          <h2>
            {t.storyTitle[0]}
            <br />
            <em>{t.storyTitle[1]}</em>
          </h2>
          <p>{t.promiseText}</p>
          <Link href={`/${locale}/shop`} className="button button-dark">
            {t.explore} <span>↗</span>
          </Link>
        </div>
      </section>
      <div className="marquee">
        {[...t.marquee, ...t.marquee.slice(0, 2)].join(" ✦ ")} ✦
      </div>
    </main>
  );
}
