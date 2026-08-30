import Image from "next/image";
import Link from "next/link";
import { notFound } from "next/navigation";
import type { Metadata } from "next";
import { BuyBox } from "@/components/BuyBox";
import { isDemoMode } from "@/lib/demo";
import { api, availabilityOf, copy, isLocale, type Product } from "@/lib/shop";
export async function generateMetadata({
  params,
}: {
  params: Promise<{ locale: string; slug: string }>;
}): Promise<Metadata> {
  const { locale, slug } = await params;
  if (!isLocale(locale)) return {};
  try {
    const p = await api<Product>(`/api/products/${slug}?locale=${locale}`);
    return { title: p.name, description: p.short };
  } catch {
    return {};
  }
}
export default async function ProductPage({
  params,
}: {
  params: Promise<{ locale: string; slug: string }>;
}) {
  const { locale, slug } = await params;
  if (!isLocale(locale)) notFound();
  let product: Product;
  try {
    product = await api<Product>(`/api/products/${slug}?locale=${locale}`);
  } catch {
    notFound();
  }
  const t = copy[locale];
  // JSON-LD describes the first variant, same one the sku always came from
  const first = product.variants[0];
  const ean = first?.ean || undefined;
  return (
    <main className="detail-page">
      <nav aria-label={t.breadcrumb}>
        <ol className="breadcrumbs">
          <li>
            <Link href={`/${locale}/shop`}>{t.shop}</Link>
          </li>
          {product.breadcrumbs.map((crumb) => (
            <li key={crumb.slug}>
              <Link
                href={`/${locale}/shop?category=${encodeURIComponent(crumb.slug)}`}
              >
                {crumb.name}
              </Link>
            </li>
          ))}
          <li aria-current="page">{product.name}</li>
        </ol>
      </nav>
      <div className="detail-layout">
        <div className="detail-visual">
          <Image
            src={product.image}
            alt={product.name}
            fill
            sizes="(max-width: 800px) 100vw, 60vw"
            priority
          />
          {/* the shared category scene stands in for a real photo until D07.2;
              in the demo it says so instead of pretending to be the product */}
          {isDemoMode && <p className="image-note">{t.illustrationNote}</p>}
        </div>
        <BuyBox product={product} locale={locale} />
      </div>
      <div className="detail-description">
        <span className="eyebrow">NODRA / {t.notesLabel}</span>
        <p>{product.description}</p>
        <ul>
          {product.details.map((d, i) => (
            <li key={i}>{d}</li>
          ))}
        </ul>
      </div>
      <script
        type="application/ld+json"
        dangerouslySetInnerHTML={{
          __html: JSON.stringify({
            "@context": "https://schema.org",
            "@type": "Product",
            name: product.name,
            description: product.short,
            image: product.image,
            brand: product.brand
              ? { "@type": "Brand", name: product.brand }
              : undefined,
            sku: first?.sku,
            mpn: first?.mpn || undefined,
            // 13 digits is an EAN-13, anything else valid goes out as plain gtin
            ...(ean ? { [ean.length === 13 ? "gtin13" : "gtin"]: ean } : {}),
            offers: {
              "@type": "Offer",
              priceCurrency: product.fromPrice.currency,
              price: (product.fromPrice.amount / 100).toFixed(2),
              // own stock is in stock, the rest is bought after the order
              availability:
                availabilityOf(product).status === "unavailable"
                  ? "https://schema.org/OutOfStock"
                  : product.inStock
                    ? "https://schema.org/InStock"
                    : "https://schema.org/BackOrder",
            },
          }).replace(/</g, "\\u003c"),
        }}
      />
    </main>
  );
}
