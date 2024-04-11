import Engine from '@ember/engine';
import loadInitializers from 'ember-load-initializers';
import Resolver from 'ember-resolver';
import config from './config/environment';
import services from '@fleetbase/ember-core/exports/services';
import AdminSolidServerConfigComponent from './components/admin/solid-server-config';
import SolidBrandIconComponent from './components/solid-brand-icon';

const { modulePrefix } = config;
const externalRoutes = ['console', 'extensions'];

export default class SolidEngine extends Engine {
    modulePrefix = modulePrefix;
    Resolver = Resolver;
    dependencies = {
        services,
        externalRoutes,
    };
    setupExtension = function (app, engine, universe) {
        // register menu item in header
        universe.registerHeaderMenuItem('Solid', 'console.solid-protocol', { iconComponent: SolidBrandIconComponent, iconComponentOptions: { width: 19, height: 19 }, priority: 5 });

        // register admin settings -- create a solid server menu panel with it's own setting options
        universe.registerAdminMenuPanel(
            'Solid Protocol',
            [
                {
                    title: 'Solid Server Config',
                    icon: 'sliders',
                    component: AdminSolidServerConfigComponent,
                },
            ],
            {
                slug: 'solid-server',
            }
        );
    };
}

loadInitializers(SolidEngine, modulePrefix);
