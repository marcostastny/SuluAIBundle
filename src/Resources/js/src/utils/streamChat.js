// @flow
import {createSseParser} from './sse';

const fallbackError = (message, cause) => {
    const error = new Error(message);
    // Signals the caller that no AI spend happened yet: safe to retry via the
    // blocking JSON endpoint.
    error.fallback = true;
    error.cause = cause;

    return error;
};

// POSTs like Sulu's Requester (same credentials/headers) but consumes a
// text/event-stream response, invoking onEvent(type, data) per frame.
export default function streamChat(url: string, body: Object, onEvent: (type: string, data: Object) => void): Promise<void> {
    if (typeof window === 'undefined' || !window.ReadableStream || !window.TextDecoder) {
        return Promise.reject(fallbackError('Streaming not supported'));
    }

    return fetch(url, {
        method: 'POST',
        credentials: 'same-origin',
        headers: {
            'Accept': 'text/event-stream',
            'Content-Type': 'application/json',
            'X-Requested-With': 'XMLHttpRequest',
        },
        body: JSON.stringify(body),
    }).then(
        (response) => {
            const contentType = response.headers.get('Content-Type') || '';
            if (!response.ok || !contentType.startsWith('text/event-stream') || !response.body) {
                throw fallbackError('Streaming unavailable');
            }

            const reader = response.body.getReader();
            const decoder = new TextDecoder();
            const parser = createSseParser(onEvent);

            const read = () => reader.read().then(({done, value}) => {
                if (done) {
                    parser.push(decoder.decode());
                    parser.end();

                    return undefined;
                }
                parser.push(decoder.decode(value, {stream: true}));

                return read();
            });

            return read();
        },
        (error) => {
            throw fallbackError('Streaming request failed', error);
        }
    );
}
