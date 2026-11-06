/**
 * Same-origin JSON over the board's declared operations: the browser's session cookie, and Laravel's `XSRF-TOKEN`
 * cookie echoed as `X-XSRF-TOKEN` on a write. Every operation answers `{ data: … }`; a refusal throws a
 * {@see RequestError} carrying the status and, on a 422, the field messages.
 */
export class RequestError extends Error {
    constructor(
        message: string,
        public readonly status: number,
        public readonly errors: Record<string, string[]> = {},
    ) {
        super(message);
        this.name = 'RequestError';
    }
}

const HEADERS = { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' };

function xsrfToken(): string | null {
    const match = document.cookie.match(/(?:^|;\s*)XSRF-TOKEN=([^;]*)/);

    return match ? decodeURIComponent(match[1]) : null;
}

async function unwrap<T>(response: Response, url: string): Promise<T> {
    if (!response.ok) {
        let body: { message?: string; errors?: Record<string, string[]> } = {};
        try {
            body = (await response.json()) as typeof body;
        } catch {
            // A non-JSON refusal keeps the status line.
        }
        const first = body.errors ? Object.values(body.errors)[0]?.[0] : undefined;

        throw new RequestError(first ?? body.message ?? `${response.status} from ${url}`, response.status, body.errors ?? {});
    }

    return ((await response.json()) as { data: T }).data;
}

export async function getJson<T>(url: string): Promise<T> {
    return unwrap<T>(await fetch(url, { credentials: 'same-origin', headers: HEADERS }), url);
}

export async function postJson<T>(url: string, body: unknown): Promise<T> {
    const token = xsrfToken();

    return unwrap<T>(
        await fetch(url, {
            method: 'POST',
            credentials: 'same-origin',
            headers: { ...HEADERS, 'Content-Type': 'application/json', ...(token ? { 'X-XSRF-TOKEN': token } : {}) },
            body: JSON.stringify(body),
        }),
        url,
    );
}

export function message(error: unknown): string {
    return error instanceof Error ? error.message : String(error);
}
