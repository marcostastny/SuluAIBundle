import streamChat from '../src/utils/streamChat';

// Minimal streaming Response stand-in: emits the given chunks, then either
// completes or rejects with the abort error when the signal fires first.
function fakeStreamResponse(chunks) {
    let index = 0;

    return {
        ok: true,
        headers: {get: () => 'text/event-stream'},
        body: {
            getReader: () => ({
                read: () => {
                    if (index < chunks.length) {
                        return Promise.resolve({done: false, value: new TextEncoder().encode(chunks[index++])});
                    }

                    return Promise.resolve({done: true});
                },
            }),
        },
    };
}

describe('streamChat', () => {
    beforeEach(() => {
        // streamChat probes for browser streaming support before fetching.
        global.window = {ReadableStream: function() {}, TextDecoder};
    });

    afterEach(() => {
        delete global.fetch;
        delete global.window;
    });

    it('passes the abort signal to fetch', () => {
        const signal = {aborted: false};
        global.fetch = jest.fn(() => Promise.resolve(fakeStreamResponse([])));

        return streamChat('/chat', {}, () => {}, signal).then(() => {
            expect(global.fetch.mock.calls[0][1].signal).toBe(signal);
        });
    });

    it('works without a signal (backwards compatible)', () => {
        const events = [];
        global.fetch = jest.fn(() => Promise.resolve(fakeStreamResponse([
            'event: delta\ndata: {"text":"Hi"}\n\n',
        ])));

        return streamChat('/chat', {}, (type, data) => events.push([type, data])).then(() => {
            expect(events).toEqual([['delta', {text: 'Hi'}]]);
        });
    });

    it('rejects without the fallback flag when the stream is aborted mid-read', () => {
        const abortError = new Error('The user aborted a request.');
        abortError.name = 'AbortError';
        global.fetch = jest.fn(() => Promise.resolve({
            ok: true,
            headers: {get: () => 'text/event-stream'},
            body: {
                getReader: () => ({
                    read: jest.fn()
                        .mockResolvedValueOnce({done: false, value: new TextEncoder().encode('event: delta\ndata: {"text":"partial"}\n\n')})
                        .mockRejectedValueOnce(abortError),
                }),
            },
        }));

        return streamChat('/chat', {}, () => {}, {aborted: true}).then(
            () => {
                throw new Error('expected rejection');
            },
            (error) => {
                expect(error.name).toBe('AbortError');
                expect(error.fallback).toBeUndefined();
            }
        );
    });
});
