// @flow

/**
 * Holds the admin Router instance, captured at application start, so
 * components rendered outside the view tree (the assistant window) can
 * navigate to admin views.
 */
class RouterStore {
    router = null;

    setRouter(router) {
        if (!this.router) {
            this.router = router;
        }
    }

    navigate(view, attributes) {
        if (!this.router) {
            return false;
        }

        this.router.navigate(view, attributes);

        return true;
    }
}

export default new RouterStore();
