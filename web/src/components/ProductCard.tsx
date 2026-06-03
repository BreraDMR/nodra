import Image from "next/image";
import Link from "next/link";
import {
  availabilityOf,
  availabilityText,
  badgeName,
  money,
  type Card,
  type Locale,
} from "@/lib/shop";
export function ProductCard({
  product,
  locale,
  priority = false,
}: {
  product: Card;
  locale: Locale;
  priority?: boolean;
}) {
  const status = availabilityOf(product).status;
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
        {/* "Bestseller" claims sales NODRA hasn't had yet, so it waits for real numbers */}
        {product.badge && product.badge !== "Bestseller" && (
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
          <p className={`product-availability ${status}`}>
            {availabilityText(availabilityOf(product), locale)}
          </p>
        </div>
        <span>{money(product.fromPrice, locale)}</span>
      </div>
    </Link>
  );
}
