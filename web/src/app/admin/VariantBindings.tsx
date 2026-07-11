"use client";
import { useEffect, useState } from "react";
import { type FeedBinding, type Send } from "./shared";

type Props = {
  send: Send;
  variantId: string;
  suppliers: string[];
};

// The feed rows the admin tied to this variant by hand (D03.3): the demo cards have
// no EANs or MPNs, so a real feed reaches them only through these links. A binding
// is remembered for every following run of that feed, keyed by supplier + SKU.
export default function VariantBindings({ send, variantId, suppliers }: Props) {
  const [bindings, setBindings] = useState<FeedBinding[]>([]);
  const [bindingsKey, setBindingsKey] = useState(0);
  const [supplier, setSupplier] = useState(suppliers[0] ?? "bike_components");
  const [sku, setSku] = useState("");
  const [busy, setBusy] = useState(false);
  const [note, setNote] = useState("");
  const [error, setError] = useState("");

  // bindingsKey reloads the list after a bind or an unbind
  useEffect(() => {
    let live = true;
    send<{ items: FeedBinding[] }>(
      `/api/admin/imports/bindings?variantId=${variantId}`,
    )
      .then((r) => live && setBindings(r.items))
      .catch(
        (e) =>
          live &&
          setError(
            e instanceof Error ? e.message : "Could not load the bindings",
          ),
      );
    return () => {
      live = false;
    };
  }, [send, variantId, bindingsKey]);

  async function bind() {
    if (sku.trim() === "") return;
    setBusy(true);
    setError("");
    setNote("");
    try {
      await send("/api/admin/imports/bindings", "POST", {
        supplier,
        supplierSku: sku.trim(),
        variantId,
      });
      setNote(`Bound ${sku.trim()} (${supplier}) to this variant.`);
      setSku("");
      setBindingsKey((key) => key + 1);
    } catch (e) {
      setError(e instanceof Error ? e.message : "Could not bind the SKU");
    } finally {
      setBusy(false);
    }
  }

  async function unbind(id: string) {
    setBusy(true);
    setError("");
    try {
      await send(`/api/admin/imports/bindings/${id}`, "DELETE");
      setNote(
        "Binding removed — the feed row goes back to matching by itself.",
      );
      setBindingsKey((key) => key + 1);
    } catch (e) {
      setError(e instanceof Error ? e.message : "Could not remove the binding");
    } finally {
      setBusy(false);
    }
  }

  return (
    <section className="admin-panel">
      <div className="panel-head">
        <div>
          <p className="eyebrow">FEED BINDINGS</p>
          <h2>Feed rows that are this variant</h2>
        </div>
      </div>
      {bindings.length ? (
        <ul className="import-list">
          {bindings.map((b) => (
            <li key={b.id}>
              <b>{b.supplierSku}</b> ({b.supplier}) — bound by {b.createdBy}{" "}
              <button
                className="admin-link"
                disabled={busy}
                onClick={() => unbind(b.id)}
              >
                Unbind
              </button>
            </li>
          ))}
        </ul>
      ) : (
        <p className="admin-empty">
          No feed row is bound to this variant. A feed with EANs or MPNs cannot
          match it — bind the supplier SKU here once and every following run
          knows.
        </p>
      )}
      <div className="import-settings">
        <label className="admin-field">
          Supplier
          <select
            value={supplier}
            onChange={(e) => setSupplier(e.target.value)}
          >
            {suppliers.map((s) => (
              <option key={s} value={s}>
                {s}
              </option>
            ))}
          </select>
        </label>
        <label className="admin-field">
          Supplier SKU
          <input
            value={sku}
            placeholder="e.g. BC-1001"
            onChange={(e) => setSku(e.target.value)}
          />
        </label>
        <button disabled={busy || sku.trim() === ""} onClick={bind}>
          Bind ↗
        </button>
      </div>
      {note && (
        <p className="admin-success" role="status">
          ✓ {note}
        </p>
      )}
      {error && (
        <p className="admin-error" role="alert">
          {error}
        </p>
      )}
      <p className="variant-note">
        A binding is a claim, not a write: the row becomes an offer only through
        the normal preview → apply path.
      </p>
    </section>
  );
}
