import {
  contactLinks,
  hasContacts,
  hasMessengers,
  hasSeller,
  seller,
} from "@/lib/contacts";
import { copy, type Locale } from "@/lib/shop";

// Floating WhatsApp/Telegram buttons, rendered only when at least one is configured
export function QuickContact({ locale }: { locale: Locale }) {
  if (!hasMessengers) return null;
  return (
    <aside className="quick-contact" aria-label={copy[locale].contactUs}>
      {contactLinks.whatsapp && (
        <a
          href={contactLinks.whatsapp}
          target="_blank"
          rel="noopener noreferrer"
        >
          WhatsApp
        </a>
      )}
      {contactLinks.telegram && (
        <a
          href={contactLinks.telegram}
          target="_blank"
          rel="noopener noreferrer"
        >
          Telegram
        </a>
      )}
    </aside>
  );
}

export function StoreFooter({ locale }: { locale: Locale }) {
  const t = copy[locale];
  const sellerLines = [
    seller.name,
    seller.ico && `${t.companyId}: ${seller.ico}`,
    seller.address,
  ].filter(Boolean);
  return (
    <footer className={hasMessengers ? "footer with-quick-contact" : "footer"}>
      <div className="footer-mark">NODRA</div>
      <div>
        <p>{t.footerTagline}</p>
        <small>{t.demoFooter}</small>
      </div>
      {hasContacts && (
        <div className="footer-contacts">
          <p>{t.contactUs}</p>
          <ul>
            {contactLinks.whatsapp && (
              <li>
                <a
                  href={contactLinks.whatsapp}
                  target="_blank"
                  rel="noopener noreferrer"
                >
                  WhatsApp
                </a>
              </li>
            )}
            {contactLinks.telegram && (
              <li>
                <a
                  href={contactLinks.telegram}
                  target="_blank"
                  rel="noopener noreferrer"
                >
                  Telegram
                </a>
              </li>
            )}
            {contactLinks.email && (
              <li>
                <a href={contactLinks.email.href}>{contactLinks.email.label}</a>
              </li>
            )}
          </ul>
        </div>
      )}
      {hasSeller && (
        <div className="footer-seller">
          <p>{t.sellerLabel}</p>
          <small>
            {sellerLines.map((line, i) => (
              <span key={i}>{line}</span>
            ))}
          </small>
        </div>
      )}
      {/* Legal links go here once the pages exist: obchodní podmínky,
          reklamační řád, odstoupení od smlouvy, osobní údaje, cookies */}
      <div className="footer-languages">
        {t.location}
        <br />© 2026 NODRA
      </div>
    </footer>
  );
}
