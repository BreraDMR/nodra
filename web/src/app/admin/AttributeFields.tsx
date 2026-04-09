"use client";
import type { AttributeDefinition, AttributeValues } from "./shared";

// Inputs for a category's effective attributes. With `inherited` set (variant editor)
// an empty field means "use the product value".
export function AttributeFields({
  title,
  definitions,
  values,
  onChange,
  inherited,
}: {
  title: string;
  definitions: AttributeDefinition[];
  values: AttributeValues;
  onChange: (values: AttributeValues) => void;
  inherited?: AttributeValues;
}) {
  const set = (key: string, value: string) =>
    onChange({ ...values, [key]: value });
  const optionLabel = (d: AttributeDefinition, value: string) =>
    d.options.find((o) => o.value === value)?.labelEn || value;
  return (
    <fieldset className="admin-fieldset">
      <legend>{title}</legend>
      {!definitions.length && (
        <p className="variant-note">This category has no attributes yet.</p>
      )}
      <div className="admin-form-grid">
        {definitions.map((d) => {
          const value = values[d.key] || "";
          const parent = inherited?.[d.key] || "";
          const label = `${d.labelEn}${d.unit ? ` · ${d.unit}` : ""}`;
          const empty = inherited
            ? `Inherit${parent ? `: ${d.type === "choice" ? optionLabel(d, parent) : parent}` : " (not set)"}`
            : "";
          if (d.type === "choice") {
            const known = d.options.some((o) => o.value === value);
            return (
              <label className="admin-field" key={d.key}>
                {label}
                <select
                  value={value}
                  onChange={(e) => set(d.key, e.target.value)}
                >
                  <option value="">{empty || "—"}</option>
                  {value && !known && (
                    <option value={value}>{value} (not an option)</option>
                  )}
                  {d.options.map((o) => (
                    <option key={o.value} value={o.value}>
                      {o.labelEn || o.value}
                    </option>
                  ))}
                </select>
              </label>
            );
          }
          return (
            <label className="admin-field" key={d.key}>
              {label}
              <input
                value={value}
                inputMode={d.type === "number" ? "decimal" : undefined}
                pattern={d.type === "number" ? "\\d+([.,]\\d+)?" : undefined}
                title={d.type === "number" ? "A number, e.g. 2.5" : undefined}
                maxLength={80}
                placeholder={empty}
                onChange={(e) => set(d.key, e.target.value)}
              />
            </label>
          );
        })}
      </div>
    </fieldset>
  );
}
