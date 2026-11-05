// src/BoardLensBar.tsx
import { useCallback, useEffect, useRef, useState } from "react";
import { jsx, jsxs } from "react/jsx-runtime";
function BoardLensBar({ url = "/exec/lens", onSwitched = reloadPage, client = fetchClient }) {
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
  }, [client, url]);
  const choose = useCallback(
    (value) => {
      if (!bar?.switchUrl || pending !== null || value === bar.mode) {
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
function message(error) {
  return error instanceof Error ? error.message : String(error);
}
function xsrfToken() {
  const match = document.cookie.match(/(?:^|;\s*)XSRF-TOKEN=([^;]*)/);
  return match ? decodeURIComponent(match[1]) : null;
}
async function unwrap(response, url) {
  if (!response.ok) {
    throw new Error(`${response.status} from ${url}`);
  }
  return (await response.json()).data;
}
var fetchClient = {
  read: async (url) => unwrap(await fetch(url, { credentials: "same-origin", headers: { Accept: "application/json", "X-Requested-With": "XMLHttpRequest" } }), url),
  choose: async (url, lens) => {
    const token = xsrfToken();
    return unwrap(
      await fetch(url, {
        method: "POST",
        credentials: "same-origin",
        headers: {
          Accept: "application/json",
          "Content-Type": "application/json",
          "X-Requested-With": "XMLHttpRequest",
          ...token ? { "X-XSRF-TOKEN": token } : {}
        },
        body: JSON.stringify({ lens })
      }),
      url
    );
  }
};

// src/ExecConsole.tsx
import { Suspense, lazy } from "react";
import { Fragment, jsx as jsx2, jsxs as jsxs2 } from "react/jsx-runtime";
function createExecConsole(resolve, frameConsole = "frame/console") {
  const FrameConsole = lazy(async () => ({ default: await resolve(frameConsole) }));
  return function ExecConsole(props) {
    const base = (props.basename ?? "/exec").replace(/\/$/, "");
    return /* @__PURE__ */ jsxs2(Fragment, { children: [
      /* @__PURE__ */ jsx2(BoardLensBar, { url: `${base}/lens` }),
      /* @__PURE__ */ jsx2(Suspense, { fallback: null, children: /* @__PURE__ */ jsx2(FrameConsole, { ...props }) })
    ] });
  };
}
function execPages(resolve) {
  let page = null;
  return {
    "exec/console": async () => ({ default: page ??= createExecConsole(resolve) })
  };
}
export {
  BoardLensBar,
  LensBanner,
  createExecConsole,
  execPages,
  fetchClient
};
//# sourceMappingURL=index.js.map