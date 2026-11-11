# SteuerboxHub

🇩🇪 [Deutsch](README.md) · 🇬🇧 English

![Symcon](https://img.shields.io/badge/Symcon-PHPModul-blue)
![Module version](https://img.shields.io/badge/Module_Version-0.6.0-blue)
![Symcon version](https://img.shields.io/badge/Symcon_Version-9.0%2B-blue)
![License](https://img.shields.io/badge/License-PolyForm_Noncommercial_1.0.0-lightgrey)
[![Check Style](https://github.com/DG65/NRGSteuerboxHub/actions/workflows/check-style.yml/badge.svg)](https://github.com/DG65/NRGSteuerboxHub/actions/workflows/check-style.yml)
[![PayPal](https://img.shields.io/badge/PayPal-Me-blue?logo=paypal)](https://paypal.me/DietmarGureth)

Symcon module that captures the grid operator's control signal under **§14a EnWG**
(the German rule on controllable consumption devices) and provides it to an energy management
system (EMS) as a top-priority constraint.

Two modules in one library, one transport path each:

- **NRG-Stack SteuerboxHub** (contacts, prefix `SBH`): potential-free contacts (ripple control
  receiver scheme, FNN three-step scheme W3/S1/S2) via existing Symcon variables of an I/O module or
  digitizer. The target value Pmin is set in the instance (with a simultaneity factor calculator).
  Contract `SBH_GetState`.
- **NRG-Stack SteuerboxHub EEBUS** (prefix `SBHE`, a control hub of its own since 0.6.0): attaches
  directly to the **NRG-Stack EEBUS Gateway** and registers over the data flow with the use cases LPC
  and LPP (limiting consumption and feed-in). The control box writes limits in watts, optionally
  time-limited. If its heartbeat is missing for more than 120 s, the failsafe limits apply until it
  sends a new limit or the minimum duration (2 to 24 h) has elapsed. Contract `SBHE_GetState` (same
  fields). So far tested against the `controlbox` simulator from eebus-go (through the gateway), not
  yet with a real control box.

The shared parts (form, Pmin calculator, number and time format) live in a base class
(`libs/SteuerboxHubBase.php`) that both modules inherit from. Up to 0.5.0 a bridge sat between the
gateway and SteuerboxHub; the Store review of 11 Nov 2026 asked for the data flow instead of public
functions between the instances.

## Installation

Via the **Symcon Module Store** in the management console: search for **SteuerboxHub** and
install it. The library contains two modules:
"NRG-Stack SteuerboxHub" (contacts) and "NRG-Stack SteuerboxHub EEBUS".

**Contacts:** create an "NRG-Stack SteuerboxHub" instance, link the input variables, set Pmin.

**EEBUS:** also install the **NRG-Stack EEBUS Gateway** from the Store (search for **EEBUS**). Then:

1. Create an "NRG-Stack EEBUS Gateway" instance, enter the address and port of the control box,
   click "Check remote device", compare the SKI and accept it; have the SKI of the gateway
   released at the remote device (or by the metering point operator).
2. Create an "NRG-Stack SteuerboxHub EEBUS" instance, choose "Change gateway" at the top and assign
   the gateway, and enter the installed PV capacity (kWp) for the feed-in limit.
3. The EMS reads the state with `SBHE_GetState`.

**Updating from 0.5.0 or earlier:** a SteuerboxHub instance set to the EEBUS transport now shows a
note and delivers no signal. The former "SteuerboxHub EEBUS-Anbindung" instance is the new EEBUS hub
(same instance, the gateway stays assigned): enter the rated values there again and switch the EMS
to `SBHE_GetState`. The old SteuerboxHub instance can be set to contacts or deleted.

Questions, experiences and feedback are welcome in the forum:
[Beta testers wanted: NRG-Stack EEBUS Gateway + SteuerboxHub](https://community.symcon.de/t/beta-tester-gesucht-nrg-stack-eebus-gateway-steuerboxhub-14a-steuerbox-per-eebus-in-reinem-php/144795)

## Why a module of its own (not part of the EMS)

1. **Legal duty before optimization:** §14a is mandatory and has to take effect, and be
   provable, even when the EMS is switched off or broken. This is the standalone rule of the
   module family.
2. **Own hardware transport:** potential-free contacts (I/O module/GPIO) or EEBUS. This is a
   device driver pattern like InverterHub/MeterHub/ChargerHub, not an optimization problem.
3. **Auditability:** a small, isolated module that publishes "limit command received/lifted"
   with a timestamp is easier to demonstrate to the grid operator.

## Division of roles

- **SteuerboxHub** only captures the signal and publishes it (no second controller).
- **EMS** consumes it through `SBH_GetState` as the highest priority and distributes the setpoints
  to the hubs (`law/grid operator > direct marketer > EMS optimization > comfort`).
- **Hubs** offer actuators and execute per device (e.g. HeishaMon: block the heating element +
  quiet mode as §14a levers, ChargerHub: lower the charging point budget).

## Contract `SBH_GetState($id): array` and `SBHE_GetState($id): array` (contractVersion 1.0)

`SBH_GetState` comes from the contacts hub (`source: contacts`), `SBHE_GetState` from the EEBUS hub
(`source: eebus`). Both return the same fields:

| Field | Type | Meaning |
|---|---|---|
| `source` | string | `contacts` or `eebus` |
| `loadDimmActive` | bool | consumption limit in effect |
| `loadPMin` | float | permitted consumption of the controllable consumers in kW (0 without a limit) |
| `loadSince` | int | Unix time since which the consumption limit applies (0 = none) |
| `feedInDimmActive` | bool | feed-in limit in effect |
| `feedInLimitPercent` | float | permitted feed-in in % (100 = unlimited); with EEBUS related to the entered installed PV capacity (kWp, as in the EMS) |
| `feedInSince` | int | Unix time since which the feed-in limit applies (0 = none) |

On the caller side (EMS) always behind `function_exists('SBH_GetState')` or `function_exists('SBHE_GetState')`.

**Note on scope:** time-variable grid fees under **Module 3** are NOT a control box topic but
pure price information. They belong in the price curve/tariff contracts (TibberGridRewards),
not here.

## Test bench

```
php .tools/test-eebus.php                                  # EEBUS path against a gateway stub
php .tools/test-eebus.php --gateway /path/EEBUSGateway     # additionally against the real gateway code
php .tools/check-standalone.php                            # standalone check
```

## License

PolyForm Noncommercial 1.0.0 (free for private and non-commercial use, commercial use requires a
license, contact DG65), see [LICENSE](LICENSE).
