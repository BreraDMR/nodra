import type { Metadata } from "next";
import "./globals.css";

export const metadata: Metadata = { title: { default: "NORDRA — Ride beyond the routine", template: "%s | NORDRA" }, description: "Considered cycling equipment for every way through." };
export default function RootLayout({ children }: Readonly<{ children: React.ReactNode }>) {
  return <html lang="en"><body>{children}</body></html>;
}
