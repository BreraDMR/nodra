import Image from "next/image";
import Link from "next/link";
import { badgeName, copy, money, type Card, type Locale } from "@/lib/shop";
export function ProductCard({
  product,
  locale,
  priority = false,
}: {
  product: Card;
  locale: Locale;
  priority?: boolean;
}) {
  return (
    <Link href={`/${locale}/shop/${product.slug}`} className="product-card">
      <div className="product-image">
        <Image
          src={product.image}
          alt={product.name}
          fill
          priority={priority}
          sizes="(max-width: 720px) 48vw, (max-width: 1100px) 33vw, 25vw"
        />
        <span className="product-arrow">↗</span>
        {product.badge && (
          <span className="product-badge">
            {badgeName(locale, product.badge)}
          </span>
        )}
      </div>
      <div className="product-meta">
        <div>
          <p className="eyebrow">{product.categoryName}</p>
          {product.brand && <p className="product-brand">{product.brand}</p>}
          <h3>{product.name}</h3>
          <p className="product-availability">
            {product.inStock ? copy[locale].available : copy[locale].out}
          </p>
        </div>
        <span>{money(product.fromPrice, locale)}</span>
      </div>
    </Link>
  );
}
