// Portfolio demo flag (P02). Set NEXT_PUBLIC_DEMO_MODE=1 before `npm run build`
// and every visitor sees that orders here are tests: no payment and no delivery
// happens. Like the other NEXT_PUBLIC_ values it is baked in at build time.
function clean(value: string | undefined): string {
  return value?.trim().toLowerCase() || "";
}

export const isDemoMode = ["1", "true", "yes", "on"].includes(
  clean(process.env.NEXT_PUBLIC_DEMO_MODE),
);
