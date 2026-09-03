# How to run the homestay

Task-sized answers for the farm side of the rooms. Each one assumes you can reach
the admin panel at `/admin` as staff. If you are meeting bookings for the first
time, walk [HOMESTAY-TUTORIAL.md](HOMESTAY-TUTORIAL.md) first.

---

## How to open a room for booking

1. Go to **Homestay → Rooms → New**.
2. Fill in the name, type, and how many the room sleeps.
3. Set the money: price per night, and an extra-guest fee if you charge for the
   third body in a double.
4. Set the stay limits: minimum and maximum nights.
5. Add photos. The first is the one the grid shows.
6. Publish it.

**Verify:** open `http://localhost:3000/homestay`. The room appears in the grid
with its price. If it does not, it is not published, or **Configuration → Homestay
settings → Offer rooms on the site** is off, which hides the whole section.

---

## How to set how far ahead people can book

Two settings under **Configuration → Homestay settings** bound every booking:

| Setting | What it does |
|---|---|
| **Days of notice needed** (`booking_lead_days`) | Nothing can be booked closer than this. Set 2 and tomorrow is refused. |
| **How far ahead rooms can be booked** (`booking_horizon_days`) | Nothing can be booked beyond this. Set 180 and next year is refused. |

**Verify:** the date fields on `/homestay` carry both as their `min` and `max`, so
the picker will not offer a date outside the window. The server checks again on
submit — a hand-edited URL does not get through.

---

## How to stop the software checking guests in

By default, a guest who settles the balance on the day they arrive is moved to
**Checked in** without staff touching it.

Turn it off at **Configuration → Homestay settings → Settling up checks the guest
in** (`auto_check_in_on_payment`).

With it off, paid stays sit on **Confirmed** and wait for you. The daily sweep
still catches them — see the next section — so switching it off does not mean
stays are stranded, it means they are checked in overnight rather than the moment
the money lands.

---

## How to fix bookings stuck on Confirmed

**Symptom:** a stay is paid in full, the guest has arrived, and it still reads
Confirmed.

The daily command that sweeps these up is:

```bash
php artisan bookings:check-in-arrivals
```

Run it by hand and the stuck stay moves. If that fixes it, the real problem is
that the scheduler is not running — the command is registered for 01:00 daily but
Laravel only fires it from cron:

```bash
* * * * * cd /path/to/backend && php artisan schedule:run >> /dev/null 2>&1
```

Without that line nothing runs and nothing complains. This is the single most
likely cause of "bookings are not updating" on a fresh deploy.

**Verify:** `php artisan schedule:list` shows `bookings:check-in-arrivals` with its
next run time.

**It will deliberately leave alone:** unpaid stays, part-paid stays, stays whose
arrival date has not come round yet, and stays on a pay-in-full plan.

---

## How to cancel a stay and give the money back

1. Open the booking under **Homestay → Bookings**.
2. Move it to **Cancelled**.
3. Raise the refund against the payment on the booking.

Cancelling releases every night the stay was holding, immediately. The room is
bookable again by anyone the moment you save.

A cancelled booking is refundable in full. What the guest is actually owed after
your cancellation terms is a decision you make; the software does not withhold a
share on your behalf. Put your terms in **Configuration → Homestay settings → What
happens if a guest cancels** so the guest reads them before booking, not after.

---

## How to see why a room shows as unavailable

Availability is not computed from date ranges. Every night of a stay is a row in
`booking_nights`, and a night is either held or it is not.

```bash
php artisan tinker
>>> \App\Models\Booking::where('booking_number', 'GH-...')->first()->nights()->pluck('night');
```

Rules worth knowing when a date looks wrong:

- A stay holds every night **except** the one it leaves on. A guest leaving
  Thursday frees Thursday, so another guest can arrive Thursday. Back-to-back
  stays are normal, not a bug.
- A **checked-out** stay still holds its nights. History is not deleted; that room
  genuinely was occupied.
- Cancelling frees the nights. Nothing else does.

If two people somehow appear to hold one night, they do not: `booking_nights` has a
unique index on `(room_id, night)`, so the second insert is rejected by the
database rather than trusted to application timing.

---

## How to take a booking payment

Rooms do not accept cash on delivery, and a guest is never offered pay-on-arrival.
A room is held in somebody's name and the farm wants something down.

Two plans, set per payment method:

| Plan | What the guest pays now |
|---|---|
| **All of it now** | The full total |
| **An advance now** | The configured share, with the rest due on arrival |

The advance share comes from the payment method if it pins one, otherwise from the
site-wide default. Paying the advance moves the stay to Confirmed; paying only
part of it leaves it Placed.

**Verify:** the payment on the booking names the room it was for, so a guest with
both an order and a stay can tell the two apart on their payments page.

---

## Related

- [HOMESTAY-TUTORIAL.md](HOMESTAY-TUTORIAL.md) — the whole flow end to end
- [ARCHITECTURE.md](ARCHITECTURE.md) — tables, booking lifecycle, why one row per night
- [README](../README.md#api-reference) — the 11 room and booking endpoints
- [ADMIN-PANEL-FLOW.md](ADMIN-PANEL-FLOW.md) — every status machine on one page
