"use client";
import { useMemo, useSyncExternalStore } from "react";
import type { CartItem } from "./shop";
// the -czk key drops carts saved before D00.5: those lines carried euro prices,
// and mixing them into a koruna basket would sum two currencies into one number
const CART_KEY = "nodra-cart-czk";
export function readCart(): CartItem[] {
  try {
    return JSON.parse(localStorage.getItem(CART_KEY) || "[]") as CartItem[];
  } catch {
    return [];
  }
}
export function writeCart(items: CartItem[]): void {
  localStorage.setItem(CART_KEY, JSON.stringify(items));
  window.dispatchEvent(new Event("cart-updated"));
}
export function addCart(item: CartItem): void {
  const items = readCart();
  const existing = items.find((x) => x.variantId === item.variantId);
  if (existing)
    existing.quantity = Math.min(10, existing.quantity + item.quantity);
  else items.push(item);
  writeCart(items);
}

function subscribe(callback: () => void): () => void {
  window.addEventListener("cart-updated", callback);
  window.addEventListener("storage", callback);
  return () => {
    window.removeEventListener("cart-updated", callback);
    window.removeEventListener("storage", callback);
  };
}
function snapshot(): string {
  return localStorage.getItem(CART_KEY) || "[]";
}
export function useCartItems(): CartItem[] {
  const raw = useSyncExternalStore(subscribe, snapshot, () => "[]");
  return useMemo(() => {
    try {
      return JSON.parse(raw) as CartItem[];
    } catch {
      return [];
    }
  }, [raw]);
}
