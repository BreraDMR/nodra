import type { Metadata } from "next";
import "./globals.css";
import { headers } from "next/headers";

// Storefront title and description come per locale from [locale]/layout.tsx
export const metadata: Metadata = {
  title: { default: "NODRA", template: "%s | NODRA" },
};
export default async function RootLayout({
  children,
}: Readonly<{ children: React.ReactNode }>) {
  const incoming = await headers();
  const locale = incoming.get("x-nodra-locale") || "en";
  return (
    <html lang={locale} data-scroll-behavior="smooth">
      <body>{children}</body>
    </html>
  );
}
