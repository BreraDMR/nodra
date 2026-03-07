import type { NextConfig } from "next";

const mediaOrigin = process.env.MEDIA_ORIGIN;
const remoteMedia = mediaOrigin ? new URL(mediaOrigin) : null;
if (remoteMedia && remoteMedia.protocol !== "https:") {
  throw new Error("MEDIA_ORIGIN must use HTTPS");
}

const nextConfig: NextConfig = {
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
        source: "/api/:path*",
        destination: "http://127.0.0.1:8000/api/:path*",
      },
    ];
  },
};

export default nextConfig;
