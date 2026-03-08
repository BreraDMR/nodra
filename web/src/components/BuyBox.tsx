"use client";
import { useState } from "react";
import Link from "next/link";
import { addCart } from "@/lib/cart";
import {
  categoryName,
  copy,
  money,
  type Locale,
  type Product,
} from "@/lib/shop";
export function BuyBox({
  product,
  locale,
}: {
  product: Product;
  locale: Locale;
}) {
  const [selected, setSelected] = useState(
    product.variants.find((v) => v.stock > 0)?.id || product.variants[0]?.id,
  );
  const [added, setAdded] = useState(false);
  const variant = product.variants.find((v) => v.id === selected);
  const t = copy[locale];
  return (
    <div className="buy-box">
      <p className="eyebrow">
        NODRA / {categoryName(locale, product.category).toUpperCase()}
      </p>
      <h1>{product.name}</h1>
      <p className="product-short">{product.short}</p>
      <div className="detail-price">
        {variant
          ? money(variant.price, locale)
          : money(product.fromPrice, locale)}
      </div>
      <p className="product-availability">
        {variant?.stock ? t.available : t.out}
      </p>
      <div className="variant-heading">
        <strong>{t.details}</strong>
        <span>{variant?.sku}</span>
      </div>
      <div className="variant-picker">
        {product.variants.map((v) => (
          <button
            key={v.id}
            type="button"
            disabled={v.stock < 1}
            className={selected === v.id ? "active" : ""}
            onClick={() => {
              setSelected(v.id);
              setAdded(false);
            }}
          >
            {v.label}
          </button>
        ))}
      </div>
      <button
        className="button button-dark add-button"
        disabled={!variant || variant.stock < 1}
        onClick={() => {
          if (!variant) return;
          addCart({
            variantId: variant.id,
            slug: product.slug,
            name: product.name,
            label: variant.label,
            image: product.image,
            price: variant.price,
            quantity: 1,
          });
          setAdded(true);
        }}
      >
        {variant?.stock ? t.add : t.out}
        <span>↗</span>
      </button>
      {added && (
        <Link href={`/${locale}/basket`} className="added-note">
          ✓ {t.bag} →
        </Link>
      )}
      <div className="detail-notes">
        <p>↗ &nbsp; {t.demo}</p>
        <p>✦ &nbsp; NODRA / CURATED FOR THE EVERYDAY ESCAPE</p>
      </div>
    </div>
  );
}
