import Image from "next/image";
import Link from "next/link";
import { notFound } from "next/navigation";
import { ProductCard } from "@/components/ProductCard";
import { api, copy, isLocale, type Card } from "@/lib/shop";
export default async function Landing({
  params,
}: {
  params: Promise<{ locale: string }>;
}) {
  const { locale } = await params;
  if (!isLocale(locale)) notFound();
  const t = copy[locale];
  const { items } = await api<{ items: Card[] }>(
    `/api/products?locale=${locale}`,
  );
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
