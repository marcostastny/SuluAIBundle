import {SUPPORTED_TABS, availableTabs, tabViewName} from '../src/utils/assistantTabs';

describe('tabViewName', () => {
    it('swaps the tab segment of a page edit view name', () => {
        expect(tabViewName('sulu_page.page_edit_form.content', 'seo')).toBe('sulu_page.page_edit_form.seo');
        expect(tabViewName('sulu_page.page_edit_form.seo', 'content')).toBe('sulu_page.page_edit_form.content');
    });

    it('returns null for unusable view names', () => {
        expect(tabViewName('nodots', 'seo')).toBe(null);
        expect(tabViewName(null, 'seo')).toBe(null);
    });
});

describe('availableTabs', () => {
    it('returns supported tabs present as route siblings', () => {
        const route = {parent: {children: [
            {name: 'sulu_page.page_edit_form.content'},
            {name: 'sulu_page.page_edit_form.seo'},
            {name: 'sulu_page.page_edit_form.excerpt'},
        ]}};
        expect(availableTabs(route, 'content')).toEqual(['content', 'seo']);
    });

    it('falls back to the current tab when the route tree is missing', () => {
        expect(availableTabs(null, 'content')).toEqual(['content']);
        expect(availableTabs({parent: null}, 'seo')).toEqual(['seo']);
    });

    it('exposes exactly content and seo as supported', () => {
        expect(SUPPORTED_TABS).toEqual(['content', 'seo']);
    });
});
