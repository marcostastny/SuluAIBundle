import {createSseParser} from '../src/utils/sse';

test('parses a complete frame', () => {
    const events = [];
    const parser = createSseParser((type, data) => events.push([type, data]));

    parser.push('event: delta\ndata: {"text":"Hi"}\n\n');

    expect(events).toEqual([['delta', {text: 'Hi'}]]);
});

test('parses frames split across chunks', () => {
    const events = [];
    const parser = createSseParser((type, data) => events.push([type, data]));

    parser.push('event: del');
    parser.push('ta\ndata: {"te');
    parser.push('xt":"Hi"}\n');
    parser.push('\nevent: reset\ndata: {}\n\n');

    expect(events).toEqual([['delta', {text: 'Hi'}], ['reset', {}]]);
});

test('handles multiple frames in one chunk and CRLF line endings', () => {
    const events = [];
    const parser = createSseParser((type, data) => events.push([type, data]));

    parser.push('event: delta\r\ndata: {"text":"a"}\r\n\r\nevent: delta\r\ndata: {"text":"b"}\r\n\r\n');

    expect(events).toEqual([['delta', {text: 'a'}], ['delta', {text: 'b'}]]);
});

test('joins multi-line data and ignores invalid JSON frames', () => {
    const events = [];
    const parser = createSseParser((type, data) => events.push([type, data]));

    parser.push('event: result\ndata: {"a":\ndata: 1}\n\n');
    parser.push('event: broken\ndata: {nope\n\n');

    expect(events).toEqual([['result', {a: 1}]]);
});

test('ignores frames without data lines', () => {
    const events = [];
    const parser = createSseParser((type, data) => events.push([type, data]));

    parser.push('event: ping\n\nevent: delta\ndata: {"text":"x"}\n\n');

    expect(events).toEqual([['delta', {text: 'x'}]]);
});

test('end() flushes a trailing frame without terminator', () => {
    const events = [];
    const parser = createSseParser((type, data) => events.push([type, data]));

    parser.push('event: result\ndata: {"reply":"x"}');
    parser.end();

    expect(events).toEqual([['result', {reply: 'x'}]]);
});
