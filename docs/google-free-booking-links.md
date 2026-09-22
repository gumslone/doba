# Google free booking links: what is possible, and how

*Research note, September 2026. Nothing here is implemented as code; it
says what a Doba hotel can do today and what a real integration would take.*

Google shows a "free booking link" to the hotel's own website next to the
portals in hotel search, Maps and the hotel's knowledge panel, with a price
for the traveller's dates. Clicks cost nothing. For a hotel whose whole
reason to run Doba is to take bookings without commission, this is the most
valuable free placement there is.

There are two ways in. They are very different amounts of work.

## 1. Today, no code: rates in the Google Business Profile

Google lets a property enter its own rates and availability in its
**Google Business Profile**, by calendar or a bulk editor, and set the
**booking page URL** travellers are sent to. That URL must lead to the
hotel's own site, not to a portal — which is exactly what a Doba site is.
Changes appear in free booking links within about an hour. No connectivity
partner, no feed, no approval queue.

What to tell a hotelier running Doba:

1. Claim and verify the hotel's Business Profile.
2. Under *Rates*, set the booking page URL to the booking search of the
   Doba site, e.g. `https://hotel.example/en/booking/search`.
3. Enter the rates the website sells — the final price including taxes and
   fees, because Google's price accuracy policy compares the two.
4. Update them when the website's prices change.

The cost is step 4: it is manual, it is one price per night rather than per
room type and rate plan, and a hotel that changes prices weekly will let it
go stale. It is still the right first step for a small house, and it costs
nothing to try.

**What Doba could add cheaply to help:** an admin page listing the next 90
days' lowest available public price per night, in the order the Business
Profile calendar asks for them, so keeping the two in step is copying a
column rather than reading a rate grid. A nudge when the website's prices
changed since that page was last opened would close the loop.

## 2. A real feed: becoming (or using) a connectivity partner

A proper integration pushes prices to Google automatically. Google calls the
delivery mode **ARI** (availability, rates, inventory): XML messages posted
over HTTPS whenever something changes —

- `Transaction` (property data: room types, packages)
- `OTA_HotelRateAmountNotifRQ` (rates)
- `OTA_HotelAvailNotifRQ` (availability and restrictions)
- `OTA_HotelInvCountNotifRQ` (inventory counts)
- optionally `TaxFeeInfo`, `Promotions`, `RateModifications`,
  `ExtraGuestCharges`

— authenticated by allow-listed sender IP addresses configured in Hotel
Center, plus a hotel list feed so Google can match properties. Doba already
holds all of this data in the shape ARI wants: per-night rates, allotment,
closed / CTA / CTD / min-stay restrictions, city tax separately, and a
booking URL that accepts dates and party size.

The obstacle is not technical. The sender is a **Hotel Center partner
account**, and Google's own help says direct integration "may not be
possible due to high demand or eligibility requirements". Partners are held
to a price accuracy policy and a landing page review. A single small hotel
will not get, and should not need, a partner account of its own.

## What this means for the directory hub

This is the strongest argument yet for building the hub that the directory
protocol (§21) was designed for. Every Doba install can already publish
`/.well-known/doba.json` and answer a live quote. One hub that:

- lists every opted-in Doba hotel (free traffic, and a reason to install),
- collects their rates over the protocol they already speak, and
- is **the one Hotel Center partner** that pushes ARI for all of them,

turns "apply to Google as a partner" from something no hotel can do into
something done once, for everybody. Google evaluates one applicant with many
properties, which is what its programme is built for; each hotel flips a
switch. It is also the one free feature a closed competitor cannot copy,
because it only works when the installs are open and numerous.

Order of work, if this is pursued:

1. The hub as a directory (listing, search by place and dates, link out).
2. Apply for a Hotel Center partner account in the hub's name.
3. An ARI pusher in the hub, fed by a new authenticated "rates changed"
   notification from installs (the announce command already runs nightly).
4. Landing pages: Google sends dates and occupancy; the Doba booking search
   already accepts them, so the deep link needs only the parameter mapping.

## Sources

- [About hotel free booking links — Hotel Center Help](https://support.google.com/hotelprices/answer/10472393?hl=en)
- [Connect with more travelers using free booking links — Hotel Center Help](https://support.google.com/hotelprices/answer/10472394?hl=en)
- [Best practices for free booking links — Hotel Center Help](https://support.google.com/hotelprices/answer/10472993?hl=en)
- [How to add and manage rates and availability to your Google Business Profile — Hotel Center Help](https://support.google.com/hotelprices/answer/10684696?hl=en)
- [FAQ: rates and availability using Google Business Profile — Hotel Center Help](https://support.google.com/hotelprices/answer/11202392?hl=en)
- [ARI overview — Google for Developers](https://developers.google.com/hotels/hotel-prices/dev-guide/ari-overview)
- [Price Accuracy Policy — Hotel Center Help](https://support.google.com/hotelprices/answer/6064419?hl=en)
