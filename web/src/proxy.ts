import { NextResponse, type NextRequest } from "next/server";
export function proxy(request: NextRequest) {
  const segment = request.nextUrl.pathname.split("/")[1];
  const locale = segment === "cs" || segment === "de" || segment === "en" ? segment : "en";
  const headers = new Headers(request.headers);
  headers.set("x-nordra-locale", locale);
  return NextResponse.next({ request: { headers } });
}
export const config = { matcher: ["/cs/:path*", "/de/:path*", "/en/:path*"] };
