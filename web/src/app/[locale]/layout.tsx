import { notFound } from "next/navigation";
import { StoreHeader } from "@/components/StoreHeader";
import { isLocale } from "@/lib/shop";
export default async function LocaleLayout({ children, params }: { children: React.ReactNode; params: Promise<{locale: string}> }) {
  const { locale } = await params;
  if (!isLocale(locale)) notFound();
  return <><StoreHeader locale={locale} />{children}<footer className="footer"><div className="footer-mark">NODRA<span>®</span></div><div><p>GOOD GEAR. OPEN ROADS.</p><small>Fictional portfolio store · No real orders or payments</small></div><div className="footer-languages">PRAGUE / EVERYWHERE<br />© 2026 NODRA</div></footer></>;
}
