"use client";
import { useCallback, useEffect, useState } from "react";
import {
  ApiError,
  categoryPath,
  costBand,
  errorText,
  formatBp,
  indent,
  minorText,
  parseBp,
  parseMinor,
  scaledText,
  type AdminCategory,
  type PricingRule,
  type Send,
} from "./shared";

// 100000 bp is what the API accepts at most
const maxMarkupBp = 100000;

type RuleForm = {
  categoryId: string;
  min: string;
  max: string;
  markup: string;
  active: "yes" | "no";
};

function toForm(rule?: PricingRule): RuleForm {
  return {
    categoryId: rule?.categoryId || "",
    min: rule ? minorText(rule.minCostCzkMinor) : "",
    max: rule ? minorText(rule.maxCostCzkMinor) : "",
    markup: rule ? scaledText(rule.markupBp, 2) : "",
    active: rule && !rule.active ? "no" : "yes",
  };
}

export function PricingRulesPanel({
  categories,
  send,
  onChanged,
}: {
  categories: AdminCategory[];
  send: Send;
  onChanged: () => void;
}) {
  const [rules, setRules] = useState<PricingRule[] | null>(null);
  const [listError, setListError] = useState("");
  const [notice, setNotice] = useState("");
  // null = closed, "new" = adding, otherwise the rule id
  const [editing, setEditing] = useState<string | null>(null);
  const [form, setForm] = useState<RuleForm>(toForm);
  const [formError, setFormError] = useState("");
  const [busy, setBusy] = useState(false);
  const set = (patch: Partial<RuleForm>) => setForm({ ...form, ...patch });

  const fetchRules = useCallback(
    () => send<PricingRule[]>("/api/admin/pricing-rules"),
    [send],
  );
  useEffect(() => {
    let live = true;
    fetchRules()
      .then((result) => live && setRules(result))
      .catch(
        (e) =>
          live && setListError(errorText(e, "Could not load pricing rules")),
      );
    return () => {
      live = false;
    };
  }, [fetchRules]);
  async function load() {
    try {
      setRules(await fetchRules());
    } catch (e) {
      setListError(errorText(e, "Could not load pricing rules"));
    }
  }

  const scope = (rule: PricingRule) =>
    rule.categoryId === null
      ? "All categories"
      : categoryPath(rule.categoryId, categories, rule.categorySlug || "");

  function open(rule?: PricingRule) {
    setForm(toForm(rule));
    setFormError("");
    setEditing(rule?.id || "new");
  }

  async function submit(e: React.FormEvent<HTMLFormElement>) {
    e.preventDefault();
    if (!editing) return;
    setFormError("");
    let body;
    try {
      const min = parseMinor(form.min);
      const max = parseMinor(form.max);
      const markup = parseBp(form.markup);
      if (min === null) throw Error("Enter where the cost band starts, e.g. 0");
      if (markup === null) throw Error("Enter the markup in %, e.g. 35");
      if (max !== null && max <= min)
        throw Error("The band has to end above where it starts");
      if (markup > maxMarkupBp)
        throw Error(`The markup can be at most ${formatBp(maxMarkupBp)}`);
      body = {
        categoryId: form.categoryId || null,
        minCostCzkMinor: min,
        maxCostCzkMinor: max,
        markupBp: markup,
        active: form.active === "yes",
      };
    } catch (err) {
      setFormError(errorText(err, "Check the values"));
      return;
    }
    setBusy(true);
    try {
      await send(
        editing === "new"
          ? "/api/admin/pricing-rules"
          : `/api/admin/pricing-rules/${editing}`,
        editing === "new" ? "POST" : "PUT",
        body,
      );
      setNotice(editing === "new" ? "Rule added." : "Rule saved.");
      setEditing(null);
      await load();
      onChanged();
    } catch (err) {
      setFormError(
        err instanceof ApiError && err.status === 409
          ? `Not saved: ${err.message}`
          : errorText(err, "Could not save the rule"),
      );
    } finally {
      setBusy(false);
    }
  }

  async function remove(rule: PricingRule) {
    if (
      !confirm(
        `Delete the rule "${scope(rule)} · ${costBand(rule)} · +${formatBp(rule.markupBp)}"? Prices already set stay as they are.`,
      )
    )
      return;
    setBusy(true);
    setNotice("");
    setListError("");
    try {
      await send(`/api/admin/pricing-rules/${rule.id}`, "DELETE");
      setNotice("Rule deleted.");
      await load();
      onChanged();
    } catch (err) {
      setListError(errorText(err, "Could not delete the rule"));
    } finally {
      setBusy(false);
    }
  }

  const categoryById = new Map(categories.map((c) => [c.id, c]));
  return (
    <>
      <div className="admin-heading compact">
        <div>
          <p className="eyebrow">PRICING / MARKUP</p>
          <h1>
            Pricing rules<span>.</span>
          </h1>
          <p>
            Markup on the landed cost, by cost band. A variant takes the active
            rule of its nearest category whose band holds its cost, otherwise
            the matching default rule.
          </p>
        </div>
        <button className="admin-primary" onClick={() => open()}>
          + Add rule
        </button>
      </div>
      {notice && (
        <div className="admin-success" role="status">
          ✓ {notice}
          <button onClick={() => setNotice("")}>×</button>
        </div>
      )}
      {listError && (
        <div className="admin-error" role="alert">
          {listError}
          <button onClick={() => setListError("")}>×</button>
        </div>
      )}
      <div className="admin-table-wrap">
        <table className="admin-table">
          <thead>
            <tr>
              <th>SCOPE</th>
              <th>LANDED COST BAND</th>
              <th>MARKUP</th>
              <th>STATUS</th>
              <th></th>
            </tr>
          </thead>
          <tbody>
            {(rules || []).map((rule) => (
              <tr key={rule.id}>
                <td>
                  <strong>{scope(rule)}</strong>
                  <small>
                    {rule.categoryId === null
                      ? "default rule"
                      : rule.categorySlug}
                  </small>
                </td>
                <td>{costBand(rule)}</td>
                <td>
                  <strong>+{formatBp(rule.markupBp)}</strong>
                </td>
                <td>
                  <span
                    className={`status ${rule.active ? "published" : "inactive"}`}
                  >
                    {rule.active ? "active" : "inactive"}
                  </span>
                </td>
                <td>
                  <div className="row-actions">
                    <button className="admin-link" onClick={() => open(rule)}>
                      Edit ↗
                    </button>
                    <button
                      className="admin-link danger-link"
                      disabled={busy}
                      onClick={() => remove(rule)}
                    >
                      Delete
                    </button>
                  </div>
                </td>
              </tr>
            ))}
          </tbody>
        </table>
        {rules === null && !listError && (
          <p className="admin-empty">Loading pricing rules…</p>
        )}
        {rules?.length === 0 && (
          <p className="admin-empty">
            No rules yet, so no variant gets a suggested price.
          </p>
        )}
      </div>
      {editing && (
        <div
          className="admin-modal-backdrop"
          onMouseDown={(e) => {
            if (e.target === e.currentTarget) setEditing(null);
          }}
        >
          <div className="admin-modal">
            <div className="modal-header">
              <div>
                <p className="eyebrow">PRICING / RULE EDITOR</p>
                <h2>{editing === "new" ? "New rule" : "Edit rule"}</h2>
              </div>
              <button onClick={() => setEditing(null)} aria-label="Close">
                ×
              </button>
            </div>
            <form onSubmit={submit}>
              <div className="admin-form-grid">
                <label className="admin-field wide">
                  Category
                  <select
                    value={form.categoryId}
                    onChange={(e) => set({ categoryId: e.target.value })}
                  >
                    <option value="">All categories (default rule)</option>
                    {form.categoryId && !categoryById.has(form.categoryId) && (
                      <option value={form.categoryId}>
                        {form.categoryId} (not loaded)
                      </option>
                    )}
                    {categories.map((c) => (
                      <option key={c.id} value={c.id}>
                        {indent(c)}
                        {c.active ? "" : " (inactive)"}
                      </option>
                    ))}
                  </select>
                </label>
                <label className="admin-field">
                  Landed cost from · Kč
                  <input
                    value={form.min}
                    inputMode="decimal"
                    placeholder="e.g. 300"
                    onChange={(e) => set({ min: e.target.value })}
                    required
                  />
                </label>
                <label className="admin-field">
                  Up to (not included) · Kč
                  <input
                    value={form.max}
                    inputMode="decimal"
                    placeholder="no upper limit"
                    onChange={(e) => set({ max: e.target.value })}
                  />
                </label>
                <label className="admin-field">
                  Markup · %
                  <input
                    value={form.markup}
                    inputMode="decimal"
                    placeholder="e.g. 35"
                    onChange={(e) => set({ markup: e.target.value })}
                    required
                  />
                </label>
                <label className="admin-field">
                  Status
                  <select
                    value={form.active}
                    onChange={(e) =>
                      set({ active: e.target.value as RuleForm["active"] })
                    }
                  >
                    <option value="yes">Active</option>
                    <option value="no">Inactive</option>
                  </select>
                </label>
              </div>
              <p className="variant-note">
                The band holds its start and stops just before its end, so 300
                to 1000 Kč covers 300.00–999.99 Kč. Active bands of one scope
                may not overlap. Suggested price = landed cost + markup, rounded
                up to 10 Kč.
              </p>
              {formError && (
                <p className="admin-error" role="alert">
                  {formError}
                </p>
              )}
              <button className="admin-primary" disabled={busy}>
                Save rule ↗
              </button>
            </form>
          </div>
        </div>
      )}
    </>
  );
}
