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

- As per the latest release, this is the updated UI screenshots for Fleetbase for users to manage pods.

- You can see the full release details here: https://github.com/fleetbase/solid/pull/2

- Install Solid Extension and click link: 'Sign up for an account' This will take you to Solid to create your own Solid Server & Pods,

![image](https://github.com/fleetbase/solid/assets/58805033/e4cf882a-d04f-4abd-9107-e04cb0a47949)

- Once you head to this link, you can create your own Solid Server. You should be able to generate as per the Screenshot:

<img width="1134" alt="image" src="https://github.com/fleetbase/solid/assets/58805033/97015745-a1a6-487a-a958-fe97d0a7bca7">


- Input your Solid Server details directly into Fleetbase in company admin settings

![image](https://github.com/fleetbase/solid/assets/58805033/dcfe2953-71d4-41c0-9243-36811b52017e)

Next steps would be to continue to update the UI from feedback and also conduct thorough testing and documentation. UI enhancements will be things like viewing the specific pods created as well as last synced. 

### Features:

- Ability to link Fleetbase account with Solid Web ID later via user settings.
- View and manage data stored on Solid Pod:
  - Orders
  - Payload
  - Entity
  - Service Quote
  - Purchase Rate
- Retrieve list of Solid Pods approved to receive data.
- Ability to add approved pods to send order data too (Verification Process)
- View order details 
- Send order details 
- Select Pod to send order details too 
- Send order details to Solid partners:
  - View Order
  - Send Order
  - Select from Dropdown of Solid Partners
  - Confirmation popup for sending data
  - Review and confirm data to be sent
- Access a separate table to view all data shared with you or shared with other Solid users.

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
