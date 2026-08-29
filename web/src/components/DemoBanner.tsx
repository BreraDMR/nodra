import { isDemoMode } from "@/lib/demo";
import { copy, isLocale } from "@/lib/shop";

// A thin strip above the header that names the portfolio demo and its boundary:
// test orders only, no payments, no deliveries. Only rendered when the demo
// flag is on, so a real shop build never shows it.
export function DemoBanner({ locale }: { locale: string }) {
  if (!isDemoMode || !isLocale(locale)) return null;
  return (
    <div className="demo-banner" role="note">
      ✦ {copy[locale].demoBanner}
    </div>
  );
}
