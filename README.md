# iConomy

**iConomy** is a lightweight and robust economy core for **PocketMine-MP**. It provides the essential foundation for a server-side currency system, allowing players to earn, save, and spend money across various integrated plugins.

## Features

*   **Reliable Core:** A stable and fast engine for handling player balances.
*   **API for Developers:** Easy-to-use API for integrating shops, jobs, and other economy-based plugins.
*   **Offline Support:** Manage balances even when players are not currently on the server.
*   **Customizable Currency:** Change the currency symbol and name (e.g., $, Coins, Credits).
*   **Top List:** Built-in support to track the wealthiest players on your server.

## Commands


| Command | Description | Permission |
|---------|-------------|------------|
| `/money` | Check your current balance | `iconomy.command.money` |
| `/money pay <player> <amount>` | Send money to another player | `iconomy.command.pay` |
| `/money give <player> <amount>` | Add money to a player's account | `iconomy.admin.give` |
| `/money take <player> <amount>` | Remove money from a player's account | `iconomy.admin.take` |
| `/money set <player> <amount>` | Set a player's exact balance | `iconomy.admin.set` |
| `/money top` | View the richest players | `iconomy.command.top` |

## Configuration

```yaml
# iConomy Settings
default_balance: 1000
currency_symbol: "$"
currency_name: "Dollars"

# Database settings (SQLite by default)
database:
  type: sqlite
  file: "economy.db"
