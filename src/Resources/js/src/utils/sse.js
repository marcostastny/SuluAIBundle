// @flow

// Minimal server-sent-events parser: feed it decoded text chunks, it invokes
// onEvent(type, data) per complete frame. Frames may be split arbitrarily
// across chunks; data spanning multiple "data:" lines is joined per the spec.
export function createSseParser(onEvent: (type: string, data: Object) => void) {
    let buffer = '';

    const processFrame = (frame) => {
        let event = 'message';
        const dataLines = [];
        for (const line of frame.split('\n')) {
            if (line.startsWith('event:')) {
                event = line.slice(6).trim();
            } else if (line.startsWith('data:')) {
                dataLines.push(line.slice(5).replace(/^ /, ''));
            }
        }
        if (dataLines.length === 0) {
            return;
        }
        let data;
        try {
            data = JSON.parse(dataLines.join('\n'));
        } catch (error) {
            return;
        }
        onEvent(event, data);
    };

    return {
        push(text: string) {
            buffer += text.replace(/\r\n/g, '\n').replace(/\r/g, '\n');
            let index;
            while ((index = buffer.indexOf('\n\n')) !== -1) {
                const frame = buffer.slice(0, index);
                buffer = buffer.slice(index + 2);
                if (frame.trim() !== '') {
                    processFrame(frame);
                }
            }
        },
        end() {
            if (buffer.trim() !== '') {
                processFrame(buffer);
            }
            buffer = '';
        },
    };
}
