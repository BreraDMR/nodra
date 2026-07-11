"use client";
import { useEffect, useState } from "react";
import { type FieldOrigin, type Send } from "./shared";

type Props = {
  send: Send;
  type: "product" | "variant" | "offer";
  id: string;
};

// The origin kind as the journal keeps it: seed, feed (supplier + run) or an admin edit.
function originDetail(origin: FieldOrigin): string {
  if (origin.kind === "admin") return origin.adminEmail ?? "admin";
  if (origin.kind === "seed") return "catalog seed";
  return `${origin.supplier ?? "feed"} · run ${origin.runId?.slice(0, 8) ?? "?"}`;
}

// Field → where the value came from and when it was written (D03.3). Cards written
// before the journal existed have no rows: shown as unknown, never guessed.
export default function FieldOrigins({ send, type, id }: Props) {
  const [origins, setOrigins] = useState<Record<string, FieldOrigin> | null>(
    null,
  );
  const [failed, setFailed] = useState(false);

  useEffect(() => {
    let live = true;
    send<{ origins: Record<string, Record<string, FieldOrigin>> }>(
      `/api/admin/origins?type=${type}&ids=${id}`,
    )
      .then((r) => live && setOrigins(r.origins[id] ?? {}))
      .catch(() => live && setFailed(true));
    return () => {
      live = false;
    };
  }, [send, type, id]);

  const empty = origins === null || Object.keys(origins).length === 0;
  if (empty) {
    return (
      <section className="admin-panel">
        <div className="panel-head">
          <div>
            <p className="eyebrow">FIELD ORIGINS</p>
            <h2>Where the values came from</h2>
          </div>
        </div>
        <p className="admin-empty">
          {failed
            ? "The origin journal could not be read."
            : "No origin rows for this card — it was written before the journal existed."}
        </p>
      </section>
    );
  }

  const fields = Object.keys(origins).sort();
  return (
    <section className="admin-panel">
      <div className="panel-head">
        <div>
          <p className="eyebrow">FIELD ORIGINS</p>
          <h2>Where the values came from</h2>
        </div>
      </div>
      <div className="admin-table-wrap">
        <table className="admin-table">
          <thead>
            <tr>
              <th>FIELD</th>
              <th>SOURCE</th>
              <th>WRITTEN</th>
            </tr>
          </thead>
          <tbody>
            {fields.map((field) => {
              const origin = origins[field];
              return (
                <tr key={field}>
                  <td>
                    <code>{field}</code>
                  </td>
                  <td>
                    <span className={`status ${origin.kind}`}>
                      {origin.kind}
                    </span>{" "}
                    {originDetail(origin)}
                  </td>
                  <td>{new Date(origin.writtenAt).toLocaleString("en-GB")}</td>
                </tr>
              );
            })}
          </tbody>
        </table>
      </div>
    </section>
  );
}
