import { readFileSync } from "node:fs";
import { join } from "node:path";
import type { NextConfig } from "next";

// The API contract version rides along with every server-side API fetch (see api() in
// lib/shop.ts). It's part of the data cache key, so after an API update a page never gets
// a cached response in the old shape — that's what broke product pages twice on 28.09.
function apiContractVersion(): string {
  try {
    const contract = readFileSync(
      join(process.cwd(), "../api/config/api_doc/shop.yaml"),
      "utf8",
    );
    return /^\s+version:\s*['"]?([\w.-]+)/m.exec(contract)?.[1] ?? "unknown";
  } catch {
    // a web-only build without the api folder next to it still works, just without the guard
    return "unknown";
  }
}
const apiContract = apiContractVersion();

const mediaOrigin = process.env.MEDIA_ORIGIN;
const remoteMedia = mediaOrigin ? new URL(mediaOrigin) : null;
if (remoteMedia && remoteMedia.protocol !== "https:") {
  throw new Error("MEDIA_ORIGIN must use HTTPS");
}

const nextConfig: NextConfig = {
  env: { NODRA_API_CONTRACT: apiContract },
  images: {
    remotePatterns: remoteMedia
      ? [
          new URL(
            `${remoteMedia.origin}${remoteMedia.pathname.replace(/\/$/, "")}/**`,
          ),
        ]
      : [],
  },
  async rewrites() {
    return [
      {
        // browser-side /api calls go where the API serves; a second local stack
        // (a copy database) moves both with API_PROXY_URL and API_INTERNAL_URL
        source: "/api/:path*",
        destination: `${process.env.API_PROXY_URL || "http://127.0.0.1:8000"}/api/:path*`,
      },
    ];
  },
};

export default nextConfig;
