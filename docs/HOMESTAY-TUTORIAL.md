# Tutorial — take a stay from empty to checked out

By the end of this you will have opened a room, booked it as a guest, taken the
money, checked the guest in, and checked them out again. You will have seen every
state a booking can be in, and you will know which of them the software moves on
its own.

Allow about twenty minutes. Everything runs locally; no gateway account is needed.

## What you'll need

- The backend running (`cd backend && php artisan serve`) and the frontend
  running (`cd frontend && npm run dev`)
- A seeded database (`php artisan migrate --seed`)
- Two browser windows: the admin panel at `http://localhost:8000/admin`, the shop
  at `http://localhost:3000`

The seed gives you four rooms already. Terrace Room is ₨4,500 a night and sleeps
three, which is the one used throughout.

---

## Step 1: See the rooms a guest sees

Open `http://localhost:3000/homestay`.

Four rooms, each with a price and how many it sleeps. Above them sits a filter for
arrival, departure and party size.

That page is public. Nothing so far needs an account.

## Step 2: Ask for two nights

In the filter, pick an arrival two weeks out and a departure two nights later, set
guests to 2, and press **Find a room**.

The URL becomes something like:

```
http://localhost:3000/homestay?check_in=2026-09-17&check_out=2026-09-19&guests=2
```

The count above the grid now reads "N rooms available on those nights". Those dates
travel with you: open Terrace Room and the booking panel is already filled in.

You have a working result and you have not written a line of anything.

## Step 3: Watch the price assemble

On the room page the panel now shows:

```
रु4,500 × 2 nights      रु9,000
Total                   रु9,000
```

Change the guest count to 3. If the room charges for the third guest, an extra line
appears and the total moves. Two nights of an extra guest is two charges, not one:
the fee is per guest per night.

Set the departure before the arrival. The price block disappears, because there are
no nights to price.

## Step 4: Sign in and hold the room

A room is held in somebody's name, so this is where an account becomes necessary.
Sign in as the seeded customer:

```
customer@example.test / password
```

Back on the room, choose **eSewa** and **An advance now**, then book. You land on
your stay at `/account/bookings/<number>`, and it reads **Placed**.

Placed means the nights are yours and nobody else can take them. It does not mean
anybody has been paid.

## Step 5: Pay the advance

Pay the advance shown on the booking.

The status moves to **Confirmed**. The stay is now agreed on both sides, with a
balance still outstanding.

Pay only *part* of the advance instead and it stays on Placed. Half a deposit is
not a deposit.

## Step 6: Arrive and settle up

On the day of arrival, pay the balance.

The status moves to **Checked in** on its own, with nobody in the admin panel
touching it. That is the `auto_check_in_on_payment` setting doing its work.

Two things this deliberately does *not* do:

- Paying the whole stay up front, at booking time, does **not** check you in. You
  are not in the house because you paid early.
- Paying today for a stay that starts next month does not put you in the house
  tonight.

## Step 7: Check out

Open the booking in the admin panel under **Homestay → Bookings** and move it to
**Checked out**.

Try that on a stay that still owes money and the panel refuses. A guest does not
leave owing.

## What you built

You took one booking through the whole machine:

```
placed ──► confirmed ──► checked_in ──► checked_out
   │            │             │
   └────────────┴─────────────┴──► cancelled
```

You also saw the two rules that catch people out: the nights are held from the day
you arrive up to *but not including* the day you leave, so two stays can sit
back to back on the same room; and money only moves the booking forward when it is
the right money at the right time.

**Next:** [HOMESTAY-HOWTO.md](HOMESTAY-HOWTO.md) for the operator tasks — opening a
room, setting how far ahead people can book, refunds, and what to do when the
automatic check-in has not run.

The endpoints behind all of this are listed under **Homestay** in the
[README API reference](../README.md#api-reference). The schema and the reasoning
behind one-row-per-night are in [ARCHITECTURE.md](ARCHITECTURE.md).
