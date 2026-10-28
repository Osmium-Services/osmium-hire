# Osmium Hire

Equipment hire for businesses that rent out individual items by the **whole day**: plant machinery, vans, tools, trailers, party equipment. A customer asks for a group ("3 tonne digger") and first and last days, you confirm or decline, and they are emailed each step. No payment, deposit, delivery or insurance is handled. It is not for personal appointments (see Osmium Appointments) or shared capacity such as tables and classes.

## Day to day (admin > Hire)

A **Schedule** planner: a row for each item, a column for each day (14 shown, arrows move a week). Waiting requests and confirmed hires are bars across their days; hatched grey days are turnaround, red are out of service, light grey are closed days. Click a bar to open the hire: confirm (with optional collection and return details for the email), decline or cancel (each with an optional message and your phone number), or change the days and item. **New hire** (or double-click an empty day) books a customer who rang or called in; it is confirmed at once. Below it, the list of hires with the Deleted bin.

## Setup (admin > Services > Osmium Hire)

- **What can be hired**: groups customers choose from, each with an optional description and price text (shown only), and **turnaround days** after each hire (cleaning, inspection, repair). The item is held through its last day plus that many calendar days; customers never see it. A group is shown to customers only when it has an enabled item.
- **Items**: each one you own, with an optional reference (serial number, registration, VIN; never shown to customers) and **out of service** date ranges with a reason. A request is given the first free item in the group, preferring one with no request waiting; staff can change the item. When the days are taken the customer is offered the nearest earlier and later dates that would work.
- **Hire rules**: the first day (collection) and last day (return) must be days the business is open (Company > Location); days in between may be closed. Minimum notice in days, how far ahead, and who is emailed. Only confirmed hires block an item.

## Theme

```php
$hires = new \Osmium\Services\OsmiumHire\Models\Hires($this->osmium->dataSource);
$hires->groups(); $hires->rules($companyHours); $hires->pullNotice($_SESSION);
```

POST the form to `/api/hire` with `token`, honeypot `zip`, `group_id`, `start` and `end` (Y-m-d), `name`, `email`, optional `phone` and `notes`.

## Hooks

`hire.requested`, `hire.confirmed`, `hire.changed`, `hire.declined`, `hire.cancelled`. Payload: `hire`, `hireId`, `dataSource`, `osmium`. A service may store its own reference in the hire's `external_refs`.

## Not included

Payments and deposits, delivery, insurance, hourly hire, same-day turnaround, reminders, and quantities of the same item (each item is its own record).
