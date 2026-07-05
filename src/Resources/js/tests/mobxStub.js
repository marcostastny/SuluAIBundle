// Minimal mobx stub for unit-testing pure utilities that only need toJS.
export const toJS = (value) => JSON.parse(JSON.stringify(value));
