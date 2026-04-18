"use client";
import Link from "next/link";
import { useCartItems } from "@/lib/cart";
import { copy, type Locale } from "@/lib/shop";

// Only the basket count needs the browser, the rest of the header stays on the server
export function BasketLink({ locale }: { locale: Locale }) {
  const count = useCartItems().reduce((n, item) => n + item.quantity, 0);
  return (
    <Link href={`/${locale}/basket`} className="basket-link">
      {copy[locale].bag} <span>{count}</span>
    </Link>
  );
}
