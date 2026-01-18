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
    priceCzk: p.variants[0]?.priceCzk || 0,
    priceEur: p.variants[0]?.priceEur || 0,
    badge: p.badge || "",
    featuredRank: p.featuredRank,
  };
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
  async function login(e: React.FormEvent<HTMLFormElement>) {
    e.preventDefault();
    setBusy(true);
    setError("");
    const d = new FormData(e.currentTarget);
    try {
      const u = await json("/api/admin/login", {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({
          email: d.get("email"),
          password: d.get("password"),
        }),
      });
      setUser(u);
      await reload();
    } catch (e) {
      setError(e instanceof Error ? e.message : "Sign-in failed");
    } finally {
      setBusy(false);
    }
  }
  async function mutate(url: string, method: string, body: unknown) {
    if (!user) return;
    setBusy(true);
    setError("");
    setMessage("");
    try {
      await json(url, {
        method,
        headers: {
          "Content-Type": "application/json",
          "X-CSRF-Token": user.csrfToken,
        },
        body: JSON.stringify(body),
      });
      setMessage("Saved successfully.");
      await reload();
      return true;
    } catch (e) {
      setError(e instanceof Error ? e.message : "Save failed");
      return false;
    } finally {
      setBusy(false);
    }
  }
  return null;
}
