# Connecting your own control box module to the EMS

For modules by other authors that deliver the grid operator's §14a EnWG signal (for example another
control box integration). The EMS finds such modules through the contract if they meet it. Your module
only delivers the signal; the EMS enforces it.

## What to do

1. Offer a public module function `<PREFIX>_GetState(int $InstanceID): array`, without default values.
2. Return these fields (keep the types exactly):

| Field | Type | Meaning |
|---|---|---|
| `contractType` | string | `steuerbox` |
| `contractVersion` | string | `1.1` |
| `source` | string | free text; the EMS marks third-party entries as `ext:<PREFIX>` |
| `loadDimmActive` | bool | consumption limit in effect |
| `loadPMin` | float | permitted consumption in kW (0 without a limit) |
| `loadSince` | int | Unix time since which the consumption limit applies (0 = none) |
| `feedInDimmActive` | bool | feed-in limit in effect |
| `feedInLimitPercent` | float | permitted feed-in in % of installed PV capacity (100 = unlimited) |
| `feedInSince` | int | Unix time since which the feed-in limit applies (0 = none) |
| `failsafeActive` | bool | control box unreachable, failsafe limit applies |
| `failsafeLoadPMin` | float | consumption limit during the failure in kW |
| `failsafeFeedInLimitPercent` | float or null | feed-in limit during the failure in %, `null` = unknown |
| `failsafeDurationSec` | int | minimum duration of the failure state in s (0 = none) |
| `lastSeenAt` | int | Unix time of the last heartbeat |

3. Without a signal and without a failure: everything inactive, `loadPMin` 0, `feedInLimitPercent` 100.
4. Your module stays functional without the EMS; the EMS looks for you, never the other way round.

Reference: `SteuerboxHub/module.php` (function `GetState`).
