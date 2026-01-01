"use client";
import type { CartItem } from "./shop";
export function readCart(): CartItem[] { try { return JSON.parse(localStorage.getItem("nordra-cart") || "[]") as CartItem[]; } catch { return []; } }
export function writeCart(items: CartItem[]): void { localStorage.setItem("nordra-cart", JSON.stringify(items)); window.dispatchEvent(new Event("cart-updated")); }
export function addCart(item: CartItem): void { const items = readCart(); const existing = items.find(x => x.variantId === item.variantId); if (existing) existing.quantity = Math.min(10, existing.quantity + item.quantity); else items.push(item); writeCart(items); }
