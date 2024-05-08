import Service from '@ember/service';
import Evented from '@ember/object/evented';
import { inject as service } from '@ember/service';
import { isArray } from '@ember/array';

export default class ExplorerStateService extends Service.extend(Evented) {
    @service appCache;

    trackWithCursor(id, cursor) {
        const segments = typeof cursor === 'string' ? cursor.split(':') : [];
        const state = this.get(id);
        this.update(
            id,
            state.filter((content) => {
                return segments.includes(content.id);
            })
        );
        this.clean(id);
        return this;
    }

    track(id, content) {
        if (id === content.id) {
            this.initialize(id, content);
        } else {
            this.push(id, content);
        }

        this.clean(id);
        return this;
    }

    initialize(id, content) {
        const state = this.get(id);
        if (isArray(state) && state.length === 0) {
            state.pushObject(content);
            this.update(id, state);
        }

        return this;
    }

    push(id, content) {
        const state = this.get(id);
        if (isArray(state) && this.doesntHave(id, content)) {
            state.pushObject(content);
            this.update(id, state);
        }

        return this;
    }

    pop(id) {
        const state = this.get(id);
        if (isArray(state)) {
            state.pop();
            this.update(id, state);
        }

        return this;
    }

    has(id, content) {
        const state = this.get(id);
        return state.findIndex((_) => _.id === content.id) >= 0;
    }

    doesntHave(id, content) {
        return !this.has(id, content);
    }

    get(id) {
        return this.appCache.get(`${id}:explorer:state`, []);
    }

    update(id, state = []) {
        this.appCache.set(`${id}:explorer:state`, state);
        this.trigger('change', id, state);
        return this;
    }

    clean(id) {
        const state = this.get(id);
        if (isArray(state)) {
            const seenIds = new Set();
            this.update(
                id,
                state.filter((_) => {
                    if (seenIds.has(_.id)) {
                        return false;
                    }

                    seenIds.add(_.id);
                    return true;
                })
            );
        }

        return this;
    }
}
