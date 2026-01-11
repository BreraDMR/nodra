import type { Metadata } from "next";
import "./globals.css";
import { headers } from "next/headers";

export const metadata: Metadata = { title: { default: "NODRA — Ride beyond the routine", template: "%s | NODRA" }, description: "Considered cycling equipment for every way through." };
export default async function RootLayout({ children }: Readonly<{ children: React.ReactNode }>) {
  const incoming = await headers();
  const locale = incoming.get("x-nodra-locale") || "en";
  return <html lang={locale}><body>{children}</body></html>;
}
