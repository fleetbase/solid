import { MenuItem, ExtensionComponent } from '@fleetbase/ember-core/contracts';

export default {
    setupExtension(app, universe) {
        const menuService = universe.getService('menu');

        // Register menu item in header
        // const iconOptions = { iconComponent: new ExtensionComponent('@fleetbase/solid-engine', 'solid-brand-icon'), iconComponentOptions: { width: 19, height: 19 } };
        menuService.registerHeaderMenuItem('Solid', 'console.solid-protocol', { priority: 5 });

        // Register admin settings -- create a solid server menu panel with it's own setting options
        universe.registerAdminMenuPanel(
            'Solid Protocol',
            [
                new MenuItem({
                    title: 'Solid Server Config',
                    icon: 'sliders',
                    component: new ExtensionComponent('@fleetbase/solid-engine', 'admin/solid-server-config'),
                }),
            ],
            {
                slug: 'solid-server',
            }
        );
    },
};
