// src/BoardLensBar.tsx
import { useCallback, useEffect, useRef, useState } from "react";

// src/http.ts
var RequestError = class extends Error {
  constructor(message2, status, errors = {}) {
    super(message2);
    this.status = status;
    this.errors = errors;
    this.name = "RequestError";
  }
  status;
  errors;
};
var HEADERS = { Accept: "application/json", "X-Requested-With": "XMLHttpRequest" };
function xsrfToken() {
  const match = document.cookie.match(/(?:^|;\s*)XSRF-TOKEN=([^;]*)/);
  return match ? decodeURIComponent(match[1]) : null;
}
async function unwrap(response, url) {
  if (!response.ok) {
    let body = {};
    try {
      body = await response.json();
    } catch {
    }
    const first = body.errors ? Object.values(body.errors)[0]?.[0] : void 0;
    throw new RequestError(first ?? body.message ?? `${response.status} from ${url}`, response.status, body.errors ?? {});
  }
  return (await response.json()).data;
}
async function getJson(url) {
  return unwrap(await fetch(url, { credentials: "same-origin", headers: HEADERS }), url);
}
async function postJson(url, body) {
  const token = xsrfToken();
  return unwrap(
    await fetch(url, {
      method: "POST",
      credentials: "same-origin",
      headers: { ...HEADERS, "Content-Type": "application/json", ...token ? { "X-XSRF-TOKEN": token } : {} },
      body: JSON.stringify(body)
    }),
    url
  );
}
function message(error) {
  return error instanceof Error ? error.message : String(error);
}

// src/BoardLensBar.tsx
import { jsx, jsxs } from "react/jsx-runtime";
function BoardLensBar({ url = "/exec/lens", onSwitched = reloadPage, client = fetchClient, version = 0 }) {
  const [bar, setBar] = useState(null);
  const [failed, setFailed] = useState(null);
  const [pending, setPending] = useState(null);
  const group = useRef(null);
  useEffect(() => {
    let live = true;
    client.read(url).then(
      (read) => live && setBar(read),
      (error) => live && setFailed(message(error))
    );
    return () => {
      live = false;
    };
  }, [client, url, version]);
  const choose = useCallback(
    (value) => {
      if (!bar?.switchUrl || pending !== null || value === bar.mode || value === bar.lens) {
        return;
      }
      setPending(value);
      client.choose(bar.switchUrl, value).then(
        (next) => {
          setBar(next);
          setPending(null);
          onSwitched(next);
        },
        (error) => {
          setPending(null);
          setFailed(message(error));
        }
      );
    },
    [bar, client, onSwitched, pending]
  );
  const onKeyDown = (event) => {
    if (!bar || !["ArrowLeft", "ArrowRight", "ArrowUp", "ArrowDown"].includes(event.key)) {
      return;
    }
    const available = bar.options.filter((option) => option.available);
    const at = available.findIndex((option) => option.active);
    const step = event.key === "ArrowLeft" || event.key === "ArrowUp" ? -1 : 1;
    const next = available[(at + step + available.length) % available.length];
    if (next) {
      event.preventDefault();
      group.current?.querySelector(`[data-lens="${next.value}"]`)?.focus();
      choose(next.value);
    }
  };
  if (!bar) {
    return failed ? /* @__PURE__ */ jsxs("div", { "data-exec-lens-bar": "failed", className: "px-6 py-2 text-xs text-muted-foreground", children: [
      "The board's lens did not load: ",
      failed
    ] }) : null;
  }
  return /* @__PURE__ */ jsxs("div", { "data-exec-lens-bar": bar.mode, className: "sticky top-0 z-30 border-b border-border bg-background/95 backdrop-blur", children: [
    /* @__PURE__ */ jsxs("div", { className: "flex flex-wrap items-center gap-3 px-6 py-2", children: [
      /* @__PURE__ */ jsx("span", { id: "exec-lens-label", className: "text-xs font-medium uppercase tracking-wide text-muted-foreground", children: "Lens" }),
      /* @__PURE__ */ jsx(
        "div",
        {
          ref: group,
          role: "radiogroup",
          "aria-labelledby": "exec-lens-label",
          onKeyDown,
          className: "inline-flex rounded-md border border-border bg-muted p-0.5",
          children: bar.options.map((option) => /* @__PURE__ */ jsx(
            "button",
            {
              type: "button",
              role: "radio",
              "aria-checked": option.active,
              tabIndex: option.active ? 0 : -1,
              disabled: !option.available || pending !== null,
              title: option.description,
              "data-lens": option.value,
              onClick: () => choose(option.value),
              className: "rounded px-3 py-1 text-sm transition-colors disabled:cursor-not-allowed disabled:opacity-50 " + (option.active ? "bg-background font-medium text-foreground shadow-sm" : "text-muted-foreground hover:text-foreground"),
              children: option.label
            },
            option.value
          ))
        }
      ),
      bar.scenarios && bar.scenarios.length > 0 && /* @__PURE__ */ jsxs("label", { className: "inline-flex items-center gap-2 text-xs text-muted-foreground", children: [
        /* @__PURE__ */ jsx("span", { children: "Scenario" }),
        /* @__PURE__ */ jsxs(
          "select",
          {
            "data-exec-lens-scenario": true,
            value: bar.scenarios.find((option) => option.active)?.value ?? "",
            disabled: !bar.switchUrl || pending !== null,
            onChange: (event) => event.target.value !== "" && choose(event.target.value),
            className: "rounded-md border border-border bg-background px-2 py-1 text-sm text-foreground",
            children: [
              /* @__PURE__ */ jsx("option", { value: "", children: "Choose a scenario\u2026" }),
              bar.scenarios.map((option) => /* @__PURE__ */ jsx("option", { value: option.value, title: option.description, children: option.label }, option.value))
            ]
          }
        )
      ] }),
      !bar.chosen && /* @__PURE__ */ jsx("span", { className: "text-xs text-muted-foreground", children: "Default lens" }),
      failed && /* @__PURE__ */ jsx("span", { className: "text-xs text-destructive", children: failed })
    ] }),
    bar.banner && /* @__PURE__ */ jsx(LensBanner, { banner: bar.banner })
  ] });
}
function LensBanner({ banner }) {
  return /* @__PURE__ */ jsxs(
    "div",
    {
      role: "status",
      "data-exec-lens-banner": banner.kind,
      className: "flex flex-wrap items-baseline gap-x-2 border-y border-warning border-l-4 bg-warning/15 px-6 py-2 text-sm text-foreground",
      children: [
        /* @__PURE__ */ jsx("strong", { className: "font-semibold", children: banner.label }),
        /* @__PURE__ */ jsx("span", { children: banner.detail })
      ]
    }
  );
}
function reloadPage() {
  window.location.reload();
}
var fetchClient = {
  read: (url) => getJson(url),
  choose: (url, lens) => postJson(url, { lens })
};

// src/ExecConsole.tsx
import { Suspense, lazy, useState as useState3 } from "react";

// src/ScenarioPanel.tsx
import { useCallback as useCallback2, useEffect as useEffect2, useId, useMemo, useRef as useRef2, useState as useState2 } from "react";
import { Fragment, jsx as jsx2, jsxs as jsxs2 } from "react/jsx-runtime";
var scenarioFetchClient = {
  catalog: (url) => getJson(url),
  preview: (url, draft) => postJson(url, draft),
  save: (url, scenario) => postJson(url, scenario),
  remove: (url, id) => postJson(url, { id }),
  show: (url, lens) => postJson(url, { lens })
};
var WATERMARK = /* @__PURE__ */ new Set(["synthesized", "projected"]);
var HEADLINE = ["mrr", "arr", "paying", "net-new-mrr"];
function emptyDraft(catalog) {
  const first = catalog?.blueprints[0]?.id ?? "";
  return { label: "New scenario", members: first ? [{ blueprint: first, count: 1, startOffsetMonths: 0 }] : [], churnRate: null, expansionRate: null };
}
function fromEntry(entry) {
  return {
    label: entry.label,
    members: entry.members.map((member) => ({ ...member })),
    churnRate: entry.churnRate,
    expansionRate: entry.expansionRate
  };
}
function kebab(label) {
  return label.toLowerCase().replace(/[^a-z0-9]+/g, "-").replace(/^-+|-+$/g, "").slice(0, 100);
}
function ScenarioPanel({
  url = "/exec/scenarios",
  client = scenarioFetchClient,
  trendCard: Trend,
  onChanged,
  onShown = reloadPage2,
  defaultOpen = false,
  previewDelay = 400
}) {
  const ids = useId();
  const [open, setOpen] = useState2(defaultOpen);
  const [catalog, setCatalog] = useState2(null);
  const [failed, setFailed] = useState2(null);
  const [draft, setDraft] = useState2(null);
  const [preview, setPreview] = useState2(null);
  const [previewError, setPreviewError] = useState2(null);
  const [previewing, setPreviewing] = useState2(false);
  const [saveId, setSaveId] = useState2("");
  const [saveError, setSaveError] = useState2(null);
  const [notice, setNotice] = useState2(null);
  const [busy, setBusy] = useState2(false);
  const sequence = useRef2(0);
  useEffect2(() => {
    if (!open || catalog) {
      return;
    }
    let live = true;
    client.catalog(url).then(
      (read) => {
        if (!live) return;
        setCatalog(read);
        setDraft((current) => current ?? emptyDraft(read));
      },
      (error) => live && setFailed(message(error))
    );
    return () => {
      live = false;
    };
  }, [catalog, client, open, url]);
  useEffect2(() => {
    if (!draft || !catalog?.previewUrl || draft.members.length === 0) {
      return;
    }
    const ticket = ++sequence.current;
    const timer = setTimeout(() => {
      setPreviewing(true);
      client.preview(catalog.previewUrl, draft).then(
        (answer) => {
          if (ticket !== sequence.current) return;
          setPreview(answer);
          setPreviewError(null);
          setPreviewing(false);
        },
        (error) => {
          if (ticket !== sequence.current) return;
          setPreviewError(error instanceof RequestError ? error : new RequestError(message(error), 0));
          setPreviewing(false);
        }
      );
    }, previewDelay);
    return () => clearTimeout(timer);
  }, [catalog?.previewUrl, client, draft, previewDelay]);
  const blueprintName = useMemo(() => new Map((catalog?.blueprints ?? []).map((b) => [b.id, b.name])), [catalog]);
  const update = useCallback2((patch) => setDraft((current) => current ? { ...current, ...patch } : current), []);
  const updateMember = (index, patch) => setDraft(
    (current) => current ? { ...current, members: current.members.map((member, i) => i === index ? { ...member, ...patch } : member) } : current
  );
  const changed = (next, text, lens) => {
    setCatalog(next);
    setNotice({ text, lens });
    onChanged?.();
  };
  const save = () => {
    if (!draft || !catalog?.saveUrl || busy) return;
    const id = saveId.trim() || kebab(draft.label);
    setBusy(true);
    setSaveError(null);
    client.save(catalog.saveUrl, { ...draft, id }).then(
      (next) => {
        setBusy(false);
        setSaveId(id);
        changed(next, `Saved \u201C${draft.label}\u201D as scenario:${id}.`, `scenario:${id}`);
      },
      (error) => {
        setBusy(false);
        setSaveError(error instanceof RequestError ? error : new RequestError(message(error), 0));
      }
    );
  };
  const remove = (entry) => {
    if (!catalog?.deleteUrl || busy) return;
    setBusy(true);
    client.remove(catalog.deleteUrl, entry.id).then(
      (next) => {
        setBusy(false);
        changed(next, `Deleted \u201C${entry.label}\u201D.`);
      },
      (error) => {
        setBusy(false);
        setNotice({ text: message(error) });
      }
    );
  };
  const show = (lens) => {
    if (!catalog?.switchUrl || busy) return;
    setBusy(true);
    client.show(catalog.switchUrl, lens).then(
      (bar) => {
        setBusy(false);
        onShown(bar);
      },
      (error) => {
        setBusy(false);
        setNotice({ text: message(error) });
      }
    );
  };
  const fieldError = (error, prefix) => error ? Object.entries(error.errors).find(([key]) => key === prefix || key.startsWith(`${prefix}.`))?.[1]?.[0] : void 0;
  const panelId = `${ids}-panel`;
  const canSave = Boolean(catalog?.canManage && catalog.storeAvailable && catalog.saveUrl);
  const headline = (preview?.revenue ?? []).filter((figure) => HEADLINE.includes(figure.key));
  const series = (preview?.trend ?? []).filter((figure) => !WATERMARK.has(figure.key));
  const watermark = preview?.revenue.find((figure) => WATERMARK.has(figure.key));
  return /* @__PURE__ */ jsxs2("section", { "data-exec-scenarios": open ? "open" : "closed", className: "border-b border-border bg-background", children: [
    /* @__PURE__ */ jsxs2("div", { className: "flex items-center gap-3 px-6 py-2", children: [
      /* @__PURE__ */ jsx2(
        "button",
        {
          type: "button",
          "aria-expanded": open,
          "aria-controls": panelId,
          onClick: () => setOpen((value) => !value),
          className: "rounded-md border border-border px-3 py-1 text-sm text-foreground hover:bg-muted",
          children: "Scenarios"
        }
      ),
      /* @__PURE__ */ jsx2("span", { className: "text-xs text-muted-foreground", children: "Compose a synthetic portfolio, preview it, and save it as a lens." })
    ] }),
    open && /* @__PURE__ */ jsx2("div", { id: panelId, className: "space-y-6 px-6 pb-6", children: !catalog ? /* @__PURE__ */ jsx2("p", { className: "text-sm text-muted-foreground", children: failed ? `The scenarios did not load: ${failed}` : "Loading scenarios\u2026" }) : /* @__PURE__ */ jsxs2(Fragment, { children: [
      /* @__PURE__ */ jsx2("div", { role: "status", "aria-live": "polite", className: "text-sm text-foreground", "data-exec-scenario-notice": true, children: notice && /* @__PURE__ */ jsxs2("span", { className: "inline-flex flex-wrap items-center gap-2", children: [
        notice.text,
        notice.lens && catalog.switchUrl && /* @__PURE__ */ jsx2(
          "button",
          {
            type: "button",
            onClick: () => show(notice.lens),
            className: "rounded-md border border-border px-2 py-0.5 text-sm hover:bg-muted",
            children: "Show on board"
          }
        )
      ] }) }),
      /* @__PURE__ */ jsxs2("section", { "aria-labelledby": `${ids}-list`, children: [
        /* @__PURE__ */ jsx2("h2", { id: `${ids}-list`, className: "mb-2 text-sm font-semibold text-foreground", children: "Scenarios on this board" }),
        /* @__PURE__ */ jsx2("ul", { className: "divide-y divide-border rounded-md border border-border", "data-exec-scenario-list": true, children: catalog.scenarios.map((entry) => /* @__PURE__ */ jsxs2("li", { "data-exec-scenario": entry.id, className: "flex flex-wrap items-center gap-3 px-3 py-2 text-sm", children: [
          /* @__PURE__ */ jsx2("span", { className: "font-medium text-foreground", children: entry.label }),
          /* @__PURE__ */ jsx2("span", { className: "rounded border border-border px-1.5 text-xs text-muted-foreground", children: entry.source === "file" ? "Declared file" : "Saved" }),
          /* @__PURE__ */ jsx2("span", { className: "text-xs text-muted-foreground", children: entry.members.map((m) => `${m.count} \xD7 ${blueprintName.get(m.blueprint) ?? m.blueprint}${m.startOffsetMonths ? ` from month ${m.startOffsetMonths + 1}` : ""}`).join(", ") }),
          /* @__PURE__ */ jsxs2("span", { className: "ml-auto inline-flex gap-2", children: [
            /* @__PURE__ */ jsx2(
              "button",
              {
                type: "button",
                disabled: entry.active || busy || !catalog.switchUrl,
                onClick: () => show(entry.lens),
                "aria-label": entry.active ? `${entry.label} is on the board` : `Show ${entry.label} on the board`,
                className: "rounded-md border border-border px-2 py-0.5 hover:bg-muted disabled:opacity-50",
                children: entry.active ? "On the board" : "Show on board"
              }
            ),
            /* @__PURE__ */ jsx2(
              "button",
              {
                type: "button",
                onClick: () => {
                  setDraft(fromEntry(entry));
                  setSaveId(entry.source === "stored" ? entry.id : "");
                },
                "aria-label": `Compose from ${entry.label}`,
                className: "rounded-md border border-border px-2 py-0.5 hover:bg-muted",
                children: "Compose from"
              }
            ),
            entry.source === "stored" && catalog.canManage && catalog.deleteUrl && /* @__PURE__ */ jsx2(
              "button",
              {
                type: "button",
                disabled: busy,
                onClick: () => remove(entry),
                "aria-label": `Delete ${entry.label}`,
                className: "rounded-md border border-border px-2 py-0.5 text-destructive hover:bg-muted disabled:opacity-50",
                children: "Delete"
              }
            )
          ] })
        ] }, entry.id)) })
      ] }),
      draft && /* @__PURE__ */ jsxs2(
        "form",
        {
          "aria-labelledby": `${ids}-composer`,
          "data-exec-scenario-composer": true,
          onSubmit: (event) => {
            event.preventDefault();
            save();
          },
          className: "grid gap-6 lg:grid-cols-2",
          children: [
            /* @__PURE__ */ jsxs2("div", { className: "space-y-4", children: [
              /* @__PURE__ */ jsx2("h2", { id: `${ids}-composer`, className: "text-sm font-semibold text-foreground", children: "Compose a scenario" }),
              /* @__PURE__ */ jsxs2("label", { className: "block text-sm", children: [
                /* @__PURE__ */ jsx2("span", { className: "mb-1 block text-muted-foreground", children: "Name" }),
                /* @__PURE__ */ jsx2(
                  "input",
                  {
                    value: draft.label,
                    onChange: (event) => update({ label: event.target.value }),
                    "aria-invalid": fieldError(previewError, "label") ? true : void 0,
                    className: "w-full rounded-md border border-border bg-background px-2 py-1 text-foreground"
                  }
                )
              ] }),
              /* @__PURE__ */ jsxs2("fieldset", { className: "space-y-2", children: [
                /* @__PURE__ */ jsx2("legend", { className: "mb-1 text-sm text-muted-foreground", children: "Members" }),
                draft.members.map((member, index) => {
                  const row = `${ids}-member-${index}`;
                  const error = fieldError(previewError, `members.${index}`) ?? fieldError(saveError, `members.${index}`);
                  return /* @__PURE__ */ jsxs2("div", { className: "flex flex-wrap items-end gap-2", "data-exec-scenario-member": index, children: [
                    /* @__PURE__ */ jsxs2("label", { className: "text-sm", children: [
                      /* @__PURE__ */ jsx2("span", { className: "mb-1 block text-xs text-muted-foreground", children: "Blueprint" }),
                      /* @__PURE__ */ jsx2(
                        "select",
                        {
                          value: member.blueprint,
                          onChange: (event) => updateMember(index, { blueprint: event.target.value }),
                          "aria-describedby": error ? `${row}-error` : void 0,
                          className: "rounded-md border border-border bg-background px-2 py-1 text-foreground",
                          children: catalog.blueprints.map((blueprint) => /* @__PURE__ */ jsxs2("option", { value: blueprint.id, children: [
                            blueprint.name,
                            " (",
                            blueprint.planSlug,
                            ", ",
                            blueprint.scenario,
                            ")"
                          ] }, blueprint.id))
                        }
                      )
                    ] }),
                    /* @__PURE__ */ jsxs2("label", { className: "text-sm", children: [
                      /* @__PURE__ */ jsx2("span", { className: "mb-1 block text-xs text-muted-foreground", children: "Tenants" }),
                      /* @__PURE__ */ jsx2(
                        "input",
                        {
                          type: "number",
                          min: 1,
                          max: 50,
                          value: member.count,
                          onChange: (event) => updateMember(index, { count: Number(event.target.value) }),
                          className: "w-20 rounded-md border border-border bg-background px-2 py-1 text-foreground"
                        }
                      )
                    ] }),
                    /* @__PURE__ */ jsxs2("label", { className: "text-sm", children: [
                      /* @__PURE__ */ jsx2("span", { className: "mb-1 block text-xs text-muted-foreground", children: "Starts after (months)" }),
                      /* @__PURE__ */ jsx2(
                        "input",
                        {
                          type: "number",
                          min: 0,
                          max: 36,
                          value: member.startOffsetMonths,
                          onChange: (event) => updateMember(index, { startOffsetMonths: Number(event.target.value) }),
                          className: "w-24 rounded-md border border-border bg-background px-2 py-1 text-foreground"
                        }
                      )
                    ] }),
                    /* @__PURE__ */ jsx2(
                      "button",
                      {
                        type: "button",
                        disabled: draft.members.length === 1,
                        onClick: () => update({ members: draft.members.filter((_, i) => i !== index) }),
                        "aria-label": `Remove member ${index + 1}`,
                        className: "rounded-md border border-border px-2 py-1 text-sm hover:bg-muted disabled:opacity-50",
                        children: "Remove"
                      }
                    ),
                    error && /* @__PURE__ */ jsx2("p", { id: `${row}-error`, className: "w-full text-xs text-destructive", children: error })
                  ] }, index);
                }),
                /* @__PURE__ */ jsx2(
                  "button",
                  {
                    type: "button",
                    onClick: () => update({
                      members: [...draft.members, { blueprint: catalog.blueprints[0]?.id ?? "", count: 1, startOffsetMonths: 0 }]
                    }),
                    className: "rounded-md border border-border px-2 py-1 text-sm hover:bg-muted",
                    children: "Add a blueprint"
                  }
                )
              ] }),
              /* @__PURE__ */ jsxs2("fieldset", { className: "flex flex-wrap gap-4", children: [
                /* @__PURE__ */ jsx2("legend", { className: "mb-1 text-sm text-muted-foreground", children: "Monthly rates for a projection (optional)" }),
                ["churnRate", "expansionRate"].map((key) => /* @__PURE__ */ jsxs2("label", { className: "text-sm", children: [
                  /* @__PURE__ */ jsx2("span", { className: "mb-1 block text-xs text-muted-foreground", children: key === "churnRate" ? "Churn (%)" : "Expansion (%)" }),
                  /* @__PURE__ */ jsx2(
                    "input",
                    {
                      type: "number",
                      min: 0,
                      max: 100,
                      step: 0.1,
                      value: draft[key] === null ? "" : Math.round(draft[key] * 1e3) / 10,
                      onChange: (event) => update({ [key]: event.target.value === "" ? null : Number(event.target.value) / 100 }),
                      className: "w-24 rounded-md border border-border bg-background px-2 py-1 text-foreground"
                    }
                  )
                ] }, key)),
                /* @__PURE__ */ jsx2("p", { className: "w-full text-xs text-muted-foreground", children: "A scenario replays its blueprints' curves as they are. The rates are kept with it for the Projection lens, which borrows them." })
              ] }),
              canSave ? /* @__PURE__ */ jsxs2("div", { className: "flex flex-wrap items-end gap-2", children: [
                /* @__PURE__ */ jsxs2("label", { className: "text-sm", children: [
                  /* @__PURE__ */ jsx2("span", { className: "mb-1 block text-xs text-muted-foreground", children: "Scenario id" }),
                  /* @__PURE__ */ jsx2(
                    "input",
                    {
                      value: saveId,
                      placeholder: kebab(draft.label),
                      onChange: (event) => setSaveId(event.target.value),
                      "aria-invalid": fieldError(saveError, "id") ? true : void 0,
                      "aria-describedby": fieldError(saveError, "id") ? `${ids}-id-error` : void 0,
                      className: "w-56 rounded-md border border-border bg-background px-2 py-1 font-mono text-foreground"
                    }
                  )
                ] }),
                /* @__PURE__ */ jsx2(
                  "button",
                  {
                    type: "submit",
                    disabled: busy,
                    className: "rounded-md bg-primary px-3 py-1 text-sm font-medium text-primary-foreground disabled:opacity-50",
                    children: "Save as scenario"
                  }
                ),
                saveError && /* @__PURE__ */ jsx2("p", { id: `${ids}-id-error`, role: "alert", className: "w-full text-xs text-destructive", children: saveError.message })
              ] }) : /* @__PURE__ */ jsx2("p", { className: "text-xs text-muted-foreground", children: catalog.storeAvailable ? "You can preview scenarios; saving one is for operators." : "This host does not store scenarios." })
            ] }),
            /* @__PURE__ */ jsxs2("div", { className: "space-y-3", "aria-busy": previewing, "data-exec-scenario-preview": true, children: [
              /* @__PURE__ */ jsx2("h2", { className: "text-sm font-semibold text-foreground", children: "Preview" }),
              watermark && /* @__PURE__ */ jsxs2("p", { className: "border-l-4 border-warning bg-warning/15 px-3 py-1 text-sm text-foreground", "data-exec-scenario-watermark": true, children: [
                /* @__PURE__ */ jsx2("strong", { className: "font-semibold", children: watermark.label }),
                " \xB7 ",
                String(watermark.value)
              ] }),
              previewError && !Object.keys(previewError.errors).some((key) => key.startsWith("members.")) && /* @__PURE__ */ jsx2("p", { role: "alert", className: "text-sm text-destructive", children: previewError.message }),
              preview && /* @__PURE__ */ jsxs2(Fragment, { children: [
                /* @__PURE__ */ jsxs2("p", { className: "text-xs text-muted-foreground", children: [
                  preview.tenants,
                  " synthetic tenants over ",
                  preview.months,
                  " months, ending ",
                  preview.asOf,
                  "."
                ] }),
                /* @__PURE__ */ jsx2("dl", { className: "grid grid-cols-2 gap-2", "data-exec-scenario-figures": true, children: headline.map((figure) => /* @__PURE__ */ jsxs2("div", { className: "rounded-md border border-border px-3 py-2", children: [
                  /* @__PURE__ */ jsx2("dt", { className: "text-xs text-muted-foreground", children: figure.label }),
                  /* @__PURE__ */ jsx2("dd", { className: "text-lg font-semibold tabular-nums text-foreground", children: String(figure.value) })
                ] }, figure.key)) }),
                Trend ? /* @__PURE__ */ jsx2(Trend, { value: { key: "scenario-mrr-trend", label: "MRR trend", figures: series } }) : /* @__PURE__ */ jsxs2("table", { className: "w-full text-xs", "data-exec-scenario-trend": true, children: [
                  /* @__PURE__ */ jsx2("caption", { className: "text-left text-muted-foreground", children: "MRR trend" }),
                  /* @__PURE__ */ jsx2("thead", { children: /* @__PURE__ */ jsxs2("tr", { children: [
                    /* @__PURE__ */ jsx2("th", { scope: "col", className: "text-left font-medium", children: "Month" }),
                    /* @__PURE__ */ jsx2("th", { scope: "col", className: "text-right font-medium", children: "MRR" })
                  ] }) }),
                  /* @__PURE__ */ jsx2("tbody", { children: series.map((figure) => /* @__PURE__ */ jsxs2("tr", { children: [
                    /* @__PURE__ */ jsx2("th", { scope: "row", className: "text-left font-normal", children: figure.label }),
                    /* @__PURE__ */ jsx2("td", { className: "text-right tabular-nums", children: String(figure.value) })
                  ] }, figure.key)) })
                ] })
              ] })
            ] })
          ]
        }
      )
    ] }) })
  ] });
}
function reloadPage2() {
  window.location.reload();
}

// src/ExecConsole.tsx
import { Fragment as Fragment2, jsx as jsx3, jsxs as jsxs3 } from "react/jsx-runtime";
function createExecConsole(resolve, options = {}) {
  const { frameConsole = "frame/console", trendCard, scenarios = true } = typeof options === "string" ? { frameConsole: options } : options;
  const FrameConsole = lazy(async () => ({ default: await resolve(frameConsole) }));
  return function ExecConsole(props) {
    const base = (props.basename ?? "/exec").replace(/\/$/, "");
    const [version, setVersion] = useState3(0);
    return /* @__PURE__ */ jsxs3(Fragment2, { children: [
      /* @__PURE__ */ jsx3(BoardLensBar, { url: `${base}/lens`, version }),
      scenarios && /* @__PURE__ */ jsx3(ScenarioPanel, { url: `${base}/scenarios`, trendCard, onChanged: () => setVersion((v) => v + 1) }),
      /* @__PURE__ */ jsx3(Suspense, { fallback: null, children: /* @__PURE__ */ jsx3(FrameConsole, { ...props }) })
    ] });
  };
}
function execPages(resolve, options = {}) {
  let page = null;
  return {
    "exec/console": async () => ({ default: page ??= createExecConsole(resolve, options) })
  };
}
export {
  BoardLensBar,
  LensBanner,
  RequestError,
  ScenarioPanel,
  createExecConsole,
  execPages,
  fetchClient,
  scenarioFetchClient
};
//# sourceMappingURL=index.js.map