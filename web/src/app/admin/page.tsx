"use client";
import Link from "next/link";
import { useCallback, useEffect, useState } from "react";
import Image from "next/image";
type User = { email: string; name: string; csrfToken: string };
type Variant = {
  id: string;
  sku: string;
  label: Record<string, string>;
  priceCzk: number;
  priceEur: number;
  stock: number;
  active: boolean;
  color: string | null;
  size: string | null;
};
type Product = {
  id: string;
  slug: string;
  category: string;
  status: string;
  name: string;
  copy: Record<string, { name: string; short: string }>;
  image: string;
  badge: string | null;
  featuredRank: number;
  variants: Variant[];
};
type Order = {
  id: string;
  reference: string;
  status: string;
  customerName: string;
  email: string;
  country: string;
  total: { amount: number; currency: string };
  createdAt: string;
};
type Dashboard = {
  orders: number;
  openOrders: number;
  revenueEurMinor: number;
  products: number;
  lowStock: { sku: string; stock: number; product: string }[];
  recentOrders: Order[];
};
type Tab = "overview" | "products" | "orders";
const fresh = {
  slug: "",
  category: "bags",
  nameCs: "",
  nameDe: "",
  nameEn: "",
  shortCs: "",
  shortDe: "",
  shortEn: "",
  image: "/images/pannier.png",
  status: "draft",
  priceCzk: 0,
  priceEur: 0,
  badge: "",
  featuredRank: 100,
};
type Form = typeof fresh;
const variantFresh = {
  sku: "",
  labelCs: "",
  labelDe: "",
  labelEn: "",
  priceCzk: 0,
  priceEur: 0,
  active: true,
  color: "",
  size: "",
};
type VariantForm = typeof variantFresh;
function fromProduct(p: Product): Form {
  const base = [...p.variants].sort(
    (a, b) =>
      Number(b.active && b.stock > 0) - Number(a.active && a.stock > 0) ||
      Number(b.active) - Number(a.active) ||
      a.sku.localeCompare(b.sku),
  )[0];
  return {
    slug: p.slug,
    category: p.category,
    nameCs: p.copy.cs?.name || "",
    nameDe: p.copy.de?.name || "",
    nameEn: p.copy.en?.name || "",
    shortCs: p.copy.cs?.short || "",
    shortDe: p.copy.de?.short || "",
    shortEn: p.copy.en?.short || "",
    image: p.image,
    status: p.status,
    priceCzk: base?.priceCzk || 0,
    priceEur: base?.priceEur || 0,
    badge: p.badge || "",
    featuredRank: p.featuredRank,
  };
}
function productPrice(p: Product): number | null {
  const active = p.variants.filter((variant) => variant.active);
  const available = active.filter((variant) => variant.stock > 0);
  const prices = (available.length ? available : active).map(
    (variant) => variant.priceEur,
  );
  return prices.length ? Math.min(...prices) : null;
}
async function json(url: string, init?: RequestInit) {
  const r = await fetch(url, { ...init, credentials: "same-origin" });
  const data = await r.json().catch(() => ({}));
  if (!r.ok) throw Error(data.message || `Request failed (${r.status})`);
  return data;
}
export default function AdminPage() {
  const [user, setUser] = useState<User | null>(null);
  const [ready, setReady] = useState(false);
  const [tab, setTab] = useState<Tab>("overview");
  const [dashboard, setDashboard] = useState<Dashboard | null>(null);
  const [products, setProducts] = useState<Product[]>([]);
  const [orders, setOrders] = useState<Order[]>([]);
  const [selectedOrder, setSelectedOrder] = useState<string | null>(null);
  const [orderDetail, setOrderDetail] = useState<Record<
    string,
    unknown
  > | null>(null);
  const [editing, setEditing] = useState<string | null>(null);
  const [variantEditing, setVariantEditing] = useState<{
    productId: string;
    id?: string;
  } | null>(null);
  const [variantForm, setVariantForm] = useState<VariantForm>(variantFresh);
  const [form, setForm] = useState<Form>(fresh);
  const [message, setMessage] = useState("");
  const [error, setError] = useState("");
  const [busy, setBusy] = useState(false);
  const reload = useCallback(async () => {
    try {
      const [d, p, o] = await Promise.all([
        json("/api/admin/dashboard"),
        json("/api/admin/products"),
        json("/api/admin/orders"),
      ]);
      setDashboard(d);
      setProducts(p.items);
      setOrders(o.items);
    } catch (e) {
      setError(e instanceof Error ? e.message : "Could not load admin data");
    }
  }, []);
  useEffect(() => {
    json("/api/admin/me")
      .then(async (u: User) => {
        setUser(u);
        await reload();
      })
      .catch(() => {})
      .finally(() => setReady(true));
  }, [reload]);
  return null;
}
