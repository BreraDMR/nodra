"use client";
import { useState } from "react";
import {
  indent,
  isVisible,
  subtreeIds,
  type AdminCategory,
  type AttributeDefinition,
  type Save,
} from "./shared";

type AttrRow = {
  rowId: number;
  key: string;
  type: AttributeDefinition["type"];
  unit: string;
  labelCs: string;
  labelDe: string;
  labelEn: string;
  filterable: boolean;
  options: string;
};
type CategoryForm = {
  slug: string;
  parentId: string;
  nameCs: string;
  nameDe: string;
  nameEn: string;
  position: number;
  active: boolean;
  attributes: AttrRow[];
};

// row ids only exist so React keeps inputs apart while keys are being typed
let nextRowId = 1;

function toRow(d?: AttributeDefinition): AttrRow {
  return {
    rowId: nextRowId++,
    key: d?.key || "",
    type: d?.type || "text",
    unit: d?.unit || "",
    labelCs: d?.labelCs || "",
    labelDe: d?.labelDe || "",
    labelEn: d?.labelEn || "",
    filterable: d?.filterable ?? true,
    // one option per line: value | cs | de | en
    options: (d?.options || [])
      .map((o) =>
        o.labelCs || o.labelDe || o.labelEn
          ? [o.value, o.labelCs || "", o.labelDe || "", o.labelEn || ""].join(
              " | ",
            )
          : o.value,
      )
      .join("\n"),
  };
}

function parseOptions(text: string) {
  return text
    .split("\n")
    .map((line) => line.trim())
    .filter(Boolean)
    .map((line) => {
      const [value, cs, de, en] = line.split("|").map((part) => part.trim());
      return {
        value,
        labelCs: cs || null,
        labelDe: de || null,
        labelEn: en || null,
      };
    });
}

export function CategoriesPanel({
  categories,
  busy,
  error,
  save,
}: {
  categories: AdminCategory[];
  busy: boolean;
  error: string;
  save: Save;
}) {
  const [editing, setEditing] = useState<string | null>(null);
  const [form, setForm] = useState<CategoryForm | null>(null);
  const byId = new Map(categories.map((c) => [c.id, c]));

  function open(category?: AdminCategory) {
    setEditing(category?.id || "new");
    setForm({
      slug: category?.slug || "",
      parentId: category?.parentId || "",
      nameCs: category?.names.cs || "",
      nameDe: category?.names.de || "",
      nameEn: category?.names.en || "",
      position: category?.position ?? 0,
      active: category?.active ?? true,
      attributes: (category?.attributes || []).map(toRow),
    });
  }

  async function submit(e: React.FormEvent<HTMLFormElement>) {
    e.preventDefault();
    if (!form || !editing) return;
    const ok = await save(
      editing === "new"
        ? "/api/admin/categories"
        : `/api/admin/categories/${editing}`,
      editing === "new" ? "POST" : "PUT",
      {
        slug: form.slug.trim(),
        parentId: form.parentId || null,
        nameCs: form.nameCs,
        nameDe: form.nameDe,
        nameEn: form.nameEn,
        position: Number(form.position),
        active: form.active,
        attributes: form.attributes.map((row) => ({
          key: row.key.trim(),
          type: row.type,
          unit: row.unit.trim() || null,
          labelCs: row.labelCs,
          labelDe: row.labelDe,
          labelEn: row.labelEn,
          filterable: row.filterable,
          options: row.type === "choice" ? parseOptions(row.options) : [],
        })),
      },
    );
    if (ok) setEditing(null);
  }

  const setRow = (rowId: number, patch: Partial<AttrRow>) =>
    form &&
    setForm({
      ...form,
      attributes: form.attributes.map((row) =>
        row.rowId === rowId ? { ...row, ...patch } : row,
      ),
    });

  // a category cannot move under itself or anything below it
  const blocked =
    editing && editing !== "new"
      ? subtreeIds(editing, categories)
      : new Set<string>();
  const parent = form?.parentId ? byId.get(form.parentId) : undefined;

  return (
    <>
      <div className="admin-heading compact">
        <div>
          <p className="eyebrow">CATALOG / STRUCTURE</p>
          <h1>
            Categories<span>.</span>
          </h1>
          <p>
            The tree the shop is browsed by, and what each branch describes.
          </p>
        </div>
        <button className="admin-primary" onClick={() => open()}>
          + Add category
        </button>
      </div>
      <div className="admin-table-wrap">
        <table className="admin-table">
          <thead>
            <tr>
              <th>CATEGORY</th>
              <th>PRODUCTS</th>
              <th>ATTRIBUTES</th>
              <th>POSITION</th>
              <th>STATUS</th>
              <th></th>
            </tr>
          </thead>
          <tbody>
            {categories.map((c) => {
              const inherited =
                c.effectiveAttributes.length - c.attributes.length;
              return (
                <tr key={c.id}>
                  <td>
                    <div
                      className="admin-tree-cell"
                      style={{ paddingLeft: c.depth * 22 }}
                    >
                      <strong>
                        {c.depth > 0 && "└ "}
                        {c.names.en}
                      </strong>
                      <small>
                        {c.slug} · {c.names.cs} · {c.names.de}
                      </small>
                    </div>
                  </td>
                  <td>{c.productCount}</td>
                  <td>
                    {c.attributes.map((a) => a.key).join(", ") || "—"}
                    {inherited > 0 && <small>+{inherited} inherited</small>}
                  </td>
                  <td>{c.position}</td>
                  <td>
                    <span
                      className={`status ${isVisible(c, byId) ? "published" : "inactive"}`}
                    >
                      {c.active
                        ? isVisible(c, byId)
                          ? "active"
                          : "parent inactive"
                        : "inactive"}
                    </span>
                  </td>
                  <td>
                    <button className="admin-link" onClick={() => open(c)}>
                      Edit ↗
                    </button>
                  </td>
                </tr>
              );
            })}
          </tbody>
        </table>
        {!categories.length && (
          <p className="admin-empty">Categories are still loading.</p>
        )}
      </div>
      {editing && form && (
        <div
          className="admin-modal-backdrop"
          onMouseDown={(e) => {
            if (e.target === e.currentTarget) setEditing(null);
          }}
        >
          <div className="admin-modal">
            <div className="modal-header">
              <div>
                <p className="eyebrow">CATALOG / CATEGORY EDITOR</p>
                <h2>{editing === "new" ? "New category" : "Edit category"}</h2>
              </div>
              <button onClick={() => setEditing(null)} aria-label="Close">
                ×
              </button>
            </div>
            <form onSubmit={submit}>
              <div className="admin-form-grid">
                <label className="admin-field">
                  URL slug
                  <input
                    value={form.slug}
                    maxLength={60}
                    pattern="[a-z0-9]+(-[a-z0-9]+)*"
                    title="Lowercase letters, numbers and hyphens"
                    onChange={(e) => setForm({ ...form, slug: e.target.value })}
                    required
                  />
                </label>
                <label className="admin-field">
                  Parent
                  <select
                    value={form.parentId}
                    onChange={(e) =>
                      setForm({ ...form, parentId: e.target.value })
                    }
                  >
                    <option value="">— Top level —</option>
                    {categories
                      .filter((c) => !blocked.has(c.id))
                      .map((c) => (
                        <option key={c.id} value={c.id}>
                          {indent(c)}
                          {c.active ? "" : " (inactive)"}
                        </option>
                      ))}
                  </select>
                </label>
                {(["nameCs", "nameDe", "nameEn"] as const).map((key, i) => (
                  <label className="admin-field" key={key}>
                    Name · {["Czech", "German", "English"][i]}
                    <input
                      value={form[key]}
                      maxLength={80}
                      onChange={(e) =>
                        setForm({ ...form, [key]: e.target.value })
                      }
                      required
                    />
                  </label>
                ))}
                <label className="admin-field">
                  Position
                  <input
                    type="number"
                    step="1"
                    value={form.position}
                    onChange={(e) =>
                      setForm({ ...form, position: Number(e.target.value) })
                    }
                  />
                </label>
                <label className="admin-field check-field">
                  <input
                    type="checkbox"
                    checked={form.active}
                    onChange={(e) =>
                      setForm({ ...form, active: e.target.checked })
                    }
                  />{" "}
                  Active in storefront
                </label>
              </div>
              <fieldset className="admin-fieldset">
                <legend>Attributes</legend>
                <p className="variant-note">
                  {parent?.effectiveAttributes.length
                    ? `Inherited from ${parent.names.en}: ${parent.effectiveAttributes
                        .map((a) => a.key)
                        .join(", ")}. `
                    : ""}
                  Keys must be unique here and in parent categories. Choice
                  options go one per line as <code>value | cs | de | en</code>.
                </p>
                {form.attributes.map((row) => (
                  <div className="attribute-row" key={row.rowId}>
                    <div className="admin-form-grid">
                      <label className="admin-field">
                        Key
                        <input
                          value={row.key}
                          maxLength={40}
                          pattern="[a-z][a-z0-9_]{1,39}"
                          title="Lowercase letters, numbers and underscores"
                          onChange={(e) =>
                            setRow(row.rowId, { key: e.target.value })
                          }
                          required
                        />
                      </label>
                      <label className="admin-field">
                        Type
                        <select
                          value={row.type}
                          onChange={(e) =>
                            setRow(row.rowId, {
                              type: e.target.value as AttrRow["type"],
                            })
                          }
                        >
                          <option value="text">text</option>
                          <option value="number">number</option>
                          <option value="choice">choice</option>
                        </select>
                      </label>
                      {(["labelCs", "labelDe", "labelEn"] as const).map(
                        (key, i) => (
                          <label className="admin-field" key={key}>
                            Label · {["Czech", "German", "English"][i]}
                            <input
                              value={row[key]}
                              maxLength={80}
                              onChange={(e) =>
                                setRow(row.rowId, { [key]: e.target.value })
                              }
                              required
                            />
                          </label>
                        ),
                      )}
                      <label className="admin-field">
                        Unit
                        <input
                          value={row.unit}
                          maxLength={12}
                          placeholder="mm, l, g…"
                          onChange={(e) =>
                            setRow(row.rowId, { unit: e.target.value })
                          }
                        />
                      </label>
                      {row.type === "choice" && (
                        <label className="admin-field wide">
                          Options · value | cs | de | en
                          <textarea
                            rows={4}
                            value={row.options}
                            placeholder={"saddle | Pod sedlo | Sattel | Saddle"}
                            onChange={(e) =>
                              setRow(row.rowId, { options: e.target.value })
                            }
                            required
                          />
                        </label>
                      )}
                      <label className="admin-field check-field">
                        <input
                          type="checkbox"
                          checked={row.filterable}
                          onChange={(e) =>
                            setRow(row.rowId, { filterable: e.target.checked })
                          }
                        />{" "}
                        Filterable in shop
                      </label>
                      <button
                        type="button"
                        className="admin-link attribute-remove"
                        onClick={() =>
                          setForm({
                            ...form,
                            attributes: form.attributes.filter(
                              (x) => x.rowId !== row.rowId,
                            ),
                          })
                        }
                      >
                        Remove attribute
                      </button>
                    </div>
                  </div>
                ))}
                <button
                  type="button"
                  className="admin-secondary"
                  onClick={() =>
                    setForm({
                      ...form,
                      attributes: [...form.attributes, toRow()],
                    })
                  }
                >
                  + Add attribute
                </button>
              </fieldset>
              {error && (
                <p className="admin-error" role="alert">
                  {error}
                </p>
              )}
              <button className="admin-primary" disabled={busy}>
                Save category ↗
              </button>
            </form>
          </div>
        </div>
      )}
    </>
  );
}
