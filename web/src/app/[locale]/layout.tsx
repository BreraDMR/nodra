import type { Metadata } from "next";
import { notFound } from "next/navigation";
import { DemoBanner } from "@/components/DemoBanner";
import { StoreFooter, QuickContact } from "@/components/StoreFooter";
import { StoreHeader } from "@/components/StoreHeader";
import { copy, isLocale } from "@/lib/shop";
export async function generateMetadata({
  params,
}: {
  params: Promise<{ locale: string }>;
}): Promise<Metadata> {
  const { locale } = await params;
  if (!isLocale(locale)) return {};
  return {
    // absolute, otherwise the root template adds " | NODRA" to the default too
    title: { absolute: copy[locale].metaTitle, template: "%s | NODRA" },
    description: copy[locale].metaDescription,
  };
}
export default async function LocaleLayout({
  children,
  params,
}: {
  children: React.ReactNode;
  params: Promise<{ locale: string }>;
}) {
  const { locale } = await params;
  if (!isLocale(locale)) notFound();
  return (
    <>
      <DemoBanner locale={locale} />
      <StoreHeader locale={locale} />
      {children}
      <StoreFooter locale={locale} />
      <QuickContact locale={locale} />
    </>
  );
}
