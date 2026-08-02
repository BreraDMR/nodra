// Shown while a server-rendered page streams (home, catalogue, product).
// The header and footer from the layout stay put, only the content pulses.
export default function Loading() {
  return (
    <main className="page-loading" aria-busy="true">
      <div className="loading-hero" />
      <div className="loading-grid">
        {Array.from({ length: 8 }, (_, i) => (
          <div className="loading-card" key={i} />
        ))}
      </div>
    </main>
  );
}
