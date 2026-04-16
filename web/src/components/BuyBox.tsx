"use client";
import { useState } from "react";
import Link from "next/link";
import { addCart } from "@/lib/cart";
import { copy, money, type Locale, type Product, type Spec } from "@/lib/shop";

// Product specs with the variant's values on top, variant wins by key
function mergeSpecs(product: Spec[], variant: Spec[]): Spec[] {
  const own = new Map(variant.map((spec) => [spec.key, spec]));
  const merged = product.map((spec) => own.get(spec.key) ?? spec);
  const known = new Set(product.map((spec) => spec.key));
  return [...merged, ...variant.filter((spec) => !known.has(spec.key))];
}
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
  const specs = mergeSpecs(product.specs, variant?.specs ?? []);
  const rows = [
    ...specs.map((spec) => ({
      key: spec.key,
      label: spec.label,
      value: spec.unit ? `${spec.value} ${spec.unit}` : spec.value,
    })),
    ...(variant?.mpn
      ? [{ key: "_mpn", label: t.mpn, value: variant.mpn }]
      : []),
    ...(variant?.ean
      ? [{ key: "_ean", label: t.ean, value: variant.ean }]
      : []),
  ];
  return (
    <div className="buy-box">
      <p className="eyebrow">NODRA / {product.categoryName.toUpperCase()}</p>
      {product.brand && <p className="buy-brand">{product.brand}</p>}
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
      {rows.length > 0 && (
        <div className="detail-specs">
          <h2 className="eyebrow">{t.specs}</h2>
          <table className="spec-table">
            <tbody>
              {rows.map((row) => (
                <tr key={row.key}>
                  <th scope="row">{row.label}</th>
                  <td>{row.value}</td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      )}
      {product.inBox && (
        <div className="detail-specs">
          <h2 className="eyebrow">{t.inBox}</h2>
          <p className="in-box">{product.inBox}</p>
        </div>
      )}
      <div className="detail-notes">
        <p>↗ &nbsp; {t.demo}</p>
        <p>✦ &nbsp; {t.announcement}</p>
      </div>
    </div>
  );
}
