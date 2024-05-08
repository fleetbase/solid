<p align="center">
    <p align="center">
        <img src="https://github.com/fleetbase/solid/assets/816371/aeff9036-6807-4e0a-a859-1dd5bee49c02" width="280" height="280" />
    </p>
    <p align="center">
        Solid Protocol Extension to Store and Share Data with Fleetbase
    </p>
</p>

# Introduction:

Solid, an innovative technology developed by Sir Tim Berners-Lee, offers a groundbreaking approach to managing data by enabling decentralized data ownership and interoperability through Linked Data principles. In the realm of logistics, Solid presents a promising solution for revolutionizing supply chain management by facilitating seamless data sharing among stakeholders. This document outlines Solid's capabilities and requirements for implementing a logistics solution, along with a user needs assessment highlighting UI/UX changes necessary for optimal user experience.

## Solid's Capabilities for Logistics:

- **Decentralized Data Ownership:** Solid allows individual entities, such as companies and suppliers, to maintain ownership of their data while granting controlled access to authorized parties. This feature ensures data security and privacy, crucial aspects in logistics operations.

- **Linked Data Sharing:** Solid's ability to establish a knowledge graph facilitates interconnectedness among disparate data sources. This is particularly beneficial for supply chain management, as it enables holistic insights and transparency across the entire supply chain network.

- **Interoperability:** Solid promotes interoperability by standardizing data formats and protocols, enabling seamless communication and integration between different systems and platforms. This facilitates smooth data exchange between logistics partners and enhances operational efficiency.

- **Collaborative Workflows:** With Solid, logistics stakeholders can collaborate in real-time, share updates, and coordinate activities effectively. This fosters greater synergy and coordination within the supply chain ecosystem, leading to improved decision-making and responsiveness.

## Requirements for Logistics Solution on Solid:

- **Solid Compatibility:** Fleetbase must be compatible with Solid's architecture, ensuring seamless integration and data exchange within the Solid ecosystem.

- **Data Security and Privacy:** Robust mechanisms for data security and privacy protection must be implemented to safeguard sensitive logistics information shared through Solid.

- **Linked Data Integration:** Fleetbase should leverage Solid's linked data capabilities to establish a comprehensive knowledge graph that connects relevant supply chain data points, enabling advanced analytics and insights generation.

- **Interoperability Standards:** Adherence to interoperability standards and protocols endorsed by Solid is essential to ensure compatibility and smooth interoperability with other logistics systems and platforms.

- **User-Friendly Interface:** The solution should feature an intuitive user interface (UI) that simplifies data interaction and facilitates seamless navigation for logistics professionals across different roles and responsibilities.

## Project Milestones:

1. **Research and planning - Milestone 1**

2. **Back End Development - Solid Server, Solid Auth, Create Pods - Milestone 2**

3. **Back End Development - Pod for instance or Pod for Organization - Milestone 3**

4. **User Interface (UI) Enhancement - Manage Pod In Admin - Milestone 4**

5. **Further User Interface (UI) Enhancement - Milestone 5**

## User Needs Assessment: UI/UX Changes:

### Prerequisites:

- **Create Pod on Solid:** Begin by registering on Solid via [solidcommunity.net](https://solidcommunity.net).
- **Retrieve Web ID:** Obtain your Web ID from example: [shivthakker.solidcommunity.net](https://shivthakker.solidcommunity.net).
- **Ability to create pod using Fleetbase directly.**

### Installation and Setup:

- Install the ‘Solid Extension’ from Fleetbase Extensions Marketplace, accessible at the Instance Level.
- Add ‘Install Solid’ functionality within Fleetbase Extensions Tab.
- Create pod & server
- Input their server 
- Input their solid ID on the server 
- Once installed, users signing up or added to the company within this instance can utilize Solid for data management.
- More info: The interface which allows the instance administrator to link the Fleetbase Solid extension to the Solid server of their preference. Due to the nature of how Solid is built on an identity basis, and Fleetbase is built as a multi-tenant platform. Each organization on Fleetbase when accessing the instance will be prompted to link their Solid OIDC account [https://github.com/fleetbase/solid/blob/main/addon/controllers/application.js#L16] to their Fleetbase organization per the instance. Once linked all data synced between Solid and Fleetbase will be via the SolidIdentity [https://github.com/fleetbase/solid/blob/main/server/src/Models/SolidIdentity.php]

### User Authentication and Account Creation:

- Choose data storage preference: Browser Storage or Solid.
- Log in or sign up with your Solid Web ID.
- Authorize fleetbase.io to access your Pod. 
- Solid allows precise control over data access permissions. Note: The current UI version (node-solid-server V5.1) supports toggling global access permissions only. If you prefer granular control, uncheck all boxes and authorize. Then, manage permissions explicitly.

### Fleetbase UI Updates 

Developer/User Guide: 

Fleetbase Solid ExtensionThis guide provides detailed instructions for integrating the Solid extension with your Fleetbase account. Solid is an innovative technology that lets users store their own data in personal online data stores, called "Pods," that they control. By using the Solid extension on Fleetbase, users can manage their data directly through the Fleetbase interface.

1. Create a New Fleetbase Account
- If you don't already have a Fleetbase account, you'll need to create one:

- Go to the Fleetbase website
- Click on "Sign Up" and follow the registration process.
- Verify your account as required.

![image](https://github.com/fleetbase/solid/assets/58805033/8dd8084c-5007-420e-b235-b46848cbe884)

2. Install the Solid Extension
- Once your Fleetbase account is active, follow these steps to install the Solid extension:

- Log in to your Fleetbase dashboard.Navigate to the Extensions section.
- Find the Solid extension and click "Install."Fleetbase Extensions Registry will be launching Q3 2024

3. Admin Configuration
- As an admin, you need to set up the Solid server for your organization:

- After installation, navigate to Admin so you can configure the Solid extension settings.
- Input the Solid server ID where your organization's data will be stored. This might be a private server or a service like solidcommunity.net

![image](https://github.com/fleetbase/solid/assets/58805033/a9324b10-f963-4a41-89c8-9ed574b450b5)


4. Get Started with Solid
- Users must individually sign up for a Solid account:

- Click ‘Sign up for an account’ or Visit https://solidcommunity.net/register or your organization's own Solid server.
- Complete the registration process to create a new Solid account.

![image](https://github.com/fleetbase/solid/assets/58805033/2bbc7406-ae08-4500-afb0-b52af675110b)

5.​​ Retrieve Your Web ID
- Your Web ID is your unique identifier in the Solid ecosystem:

- After registering, your Web ID will typically be displayed on your Solid dashboard.Note this Web ID as it will be used to link your Solid account with Fleetbase.

![image](https://github.com/fleetbase/solid/assets/58805033/1ef66dfd-fe19-4795-b1bf-3d16ba7e65f8)

6.​​ Navigate to ‘Account’ in Fleetbase
- Once your Web ID is set up:Go back to Fleetbase and navigate to the ‘Account’ section. Your User details should be pulled in automatically if properly configured.

![image](https://github.com/fleetbase/solid/assets/58805033/4d3d3090-1e3e-49e6-babc-f1c3db205080)

7.​​ Navigate to PodsTo manage your Pods:
 - In Fleetbase, find and navigate to the ‘Pods’ section after linking your Solid account.

![image](https://github.com/fleetbase/solid/assets/58805033/bcc83875-e4ed-4ae1-be83-6044eefe323e)

8.​​ Create a New POD
- Here’s how to create a new POD:

- Click “Create New POD.”Enter a name for your POD and submit.

![image](https://github.com/fleetbase/solid/assets/58805033/2677491c-7de3-4592-9f30-80448e818ff6)

9.​​ Manage Files/Folders in PODs
- To access and manage data within a POD:

- Click into the POD you wish to view.
- You’ll see files and folders stored in this POD

![image](https://github.com/fleetbase/solid/assets/58805033/e04e151a-d640-4e29-a91a-a6f09347e59d)

10. Operations on PODsSelect 

- Box Delete: To delete files or folders, select them and use the delete option.
- Re-sync / Back Up the PODs: To ensure your data is up-to-date, use the re-sync option to resynchronize the data in the POD.

![image](https://github.com/fleetbase/solid/assets/58805033/1f5ef3a8-bed8-476b-b176-cdd19b6212cd)

11. View Data in Pods
- To view specific data within a POD:

- Simply click on the file or folder you are interested in within the POD interface in Fleetbase.

![image](https://github.com/fleetbase/solid/assets/58805033/55f8af9b-f82b-406d-9434-50404c282740)

Next: We will continue work on completing the Sold <> Fleetbase integration with the following Further User Interface (UI) Enhancement for renaming Pods. Extensive User testing / bug fixes and production release. 

### Features:

Fleetbase has implemented a Solid Client which implements the Standard Solid authentication methods to communicate with the server. The Fleetbase SolidClient is able to communicate securely with the Solid protocol using the Standard DPoP encryption method for authentication provided by the Solid specification (https://solidproject.org/TR/oidc#tokens-id)

- Ability to link Fleetbase account with Solid Web ID later via user settings.

# Funding

This project is funded through [NGI0 Entrust](https://nlnet.nl/entrust), a fund established by [NLnet](https://nlnet.nl) with financial support from the European Commission's [Next Generation Internet](https://ngi.eu) program. Learn more at the [NLnet project page]( https://nlnet.nl/project/Fleetbase-Solid).

[<img src="https://nlnet.nl/logo/banner.png" alt="NLnet foundation logo" width="20%" />](https://nlnet.nl)

# Conclusion:

Solid offers a robust foundation for developing innovative logistics solutions that prioritize data ownership, interoperability, and collaboration. By leveraging Solid's capabilities and addressing specific user needs through UI/UX enhancements, logistics stakeholders can unlock new levels of efficiency, transparency, and value creation in supply chain management.

This document serves as a roadmap for designing and implementing Solid on Fleetbase, guiding stakeholders towards harnessing the full potential of decentralized, linked data sharing in the logistics domain.


---

## Overview

This monorepo contains both the frontend and backend components of the Solid Protocol integration extension for Fleetbase. The frontend is built using Ember.js and the backend is implemented in PHP.

### Requirements

* PHP 7.3.0 or above
* Ember.js v4.8 or above
* Ember CLI v4.8 or above
* Node.js v18 or above

## Structure

```
├── addon
├── app
├── assets
├── translations
├── config
├── node_modules
├── server
│ ├── config
│ ├── data
│ ├── migrations
│ ├── resources
│ ├── src
│ ├── tests
│ └── vendor
├── tests
├── testem.js
├── index.js
├── package.json
├── phpstan.neon.dist
├── phpunit.xml.dist
├── pnpm-lock.yaml
├── ember-cli-build.js
├── composer.json
├── CONTRIBUTING.md
├── LICENSE.md
├── README.md
```

## Installation

### Backend

Install the PHP packages using Composer:

```bash
composer require fleetbase/core-api
composer require fleetbase/fleetops
composer require fleetbase/solid-api
```
### Frontend

Install the Ember.js Engine/Addon:

```bash
pnpm install @fleetbase/solid-engine
```

## Usage

### Backend

🧹 Keep a modern codebase with **PHP CS Fixer**:
```bash
composer lint
```

⚗️ Run static analysis using **PHPStan**:
```bash
composer test:types
```

✅ Run unit tests using **PEST**
```bash
composer test:unit
```

🚀 Run the entire test suite:
```bash
composer test
```

### Frontend

🧹 Keep a modern codebase with **ESLint**:
```bash
pnpm lint
```

✅ Run unit tests using **Ember/QUnit**
```bash
pnpm test
pnpm test:ember
pnpm test:ember-compatibility
```

🚀 Start the Ember Addon/Engine
```bash
pnpm start
```

🔨 Build the Ember Addon/Engine
```bash
pnpm build
```

## Contributing
See the Contributing Guide for details on how to contribute to this project.

## License
This project is licensed under the MIT License.
