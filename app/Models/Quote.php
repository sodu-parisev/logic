<?php

namespace App\Models;

use App\Enums\Core\ActivityType;
use App\Enums\Core\BillFrequency;
use App\Enums\Core\BillItemType;
use App\Enums\Core\IntegrationType;
use App\Enums\Core\InvoiceStatus;
use App\Enums\Core\LeadStatus;
use App\Enums\Files\FileType;
use App\Operations\Admin\AnalysisEngine;
use App\Operations\Core\LoFileHandler;
use App\Operations\Core\MakePDF;
use App\Operations\Integrations\Accounting\Finance;
use App\Structs\STemplate;
use App\Traits\HasLogTrait;
use Exception;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * @property mixed        $services
 * @property mixed        $products
 * @property mixed        $nrc
 * @property mixed        $mrr
 * @property mixed        $term
 * @property mixed        $lead
 * @property mixed        $analysis
 * @property mixed        $account
 * @property mixed        $presentable
 * @property mixed        $net_terms
 * @property mixed        $id
 * @property mixed        $items
 * @property mixed        $coterm
 * @property mixed        $total
 * @property mixed        $activated_on
 * @property mixed        $archived
 * @property mixed        $invoiceableProducts
 * @property mixed        $hash
 * @property mixed        $commissionable
 * @property int|mixed    $account_id
 * @property int|mixed    $lead_id
 * @property mixed|string $name
 * @property mixed        $expires_on
 * @property mixed        $status
 * @property mixed        $tax
 * @property mixed        $finance_quote_id
 * @property mixed        $created_at
 *
 */
class Quote extends Model
{
    use SoftDeletes, HasLogTrait;

    protected       $guarded = ['id'];
    protected $casts   = [
        'sent_on'          => 'datetime',
        'expires_on'       => 'datetime',
        'contract_expires' => 'datetime',
        'activated_on'     => 'datetime'
    ];
    public array    $tracked = [
        'name'             => "Quote Name",
        'status'           => "Status",
        'archived'         => "Archived State",
        'preferred'        => "Preferred Quote State|bool",
        'sent_on'          => "Quote Last Sent",
        'expires_on'       => "Quote Expiration Date",
        'notes'            => "Quote Notations",
        'term'             => "Term Length",
        'presentable'      => "Presentable State|bool",
        'net_terms'        => "Net Terms for Payment",
        'activated_on'     => "Activation Date",
        'contract_name'    => "Contract Signer Name",
        'contract_ip'      => "Contract Signed from IP",
        'contract_expires' => "Contract Expiration Date",
        'declined_reason'  => "Quote Declined Reason",
        'approved'         => "Quote Approval State",
        'tax'              => "Taxation for Quote",
    ];

    /**
     * When showing the log entries for a quote, we want to
     * add the item logs as well.
     * @var array|string[]
     */
    public array $logRelationships = ['items'];

    /**
     * A quote can belong to an account
     * @return BelongsTo
     */
    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    /**
     * A quote can belong to a lead.
     * @return BelongsTo
     */
    public function lead(): BelongsTo
    {
        return $this->belongsTo(Lead::class);
    }

    /**
     * A quote can co-term another quote.
     * @return BelongsTo
     */
    public function coterm(): BelongsTo
    {
        return $this->belongsTo(Quote::class, 'coterm_id');
    }

    /**
     * A quote has many items
     * @return HasMany
     */
    public function items(): HasMany
    {
        return $this->hasMany(QuoteItem::class);
    }

    /**
     * Get a list of services in the quote
     * @return HasMany
     */
    public function services(): HasMany
    {
        return $this->hasMany(QuoteItem::class)->whereHas('item', function ($t) {
            $t->where('type', 'services');
        });
    }

    /**
     * Get a list of products for the quote
     * @return HasMany
     */
    public function products(): HasMany
    {
        return $this->hasMany(QuoteItem::class)->whereHas('item', function ($t) {
            $t->where('type', 'products');
        });
    }

    /**
     * A quote can have an assigned coupon from the shop.
     * @return BelongsTo
     */
    public function coupon(): BelongsTo
    {
        return $this->belongsTo(Coupon::class);
    }

    /**
     * Get Total Value Attribute
     * @return float
     */
    public function getTotalValueAttribute(): float
    {
        return $this->analysis->profit;
    }

    /**
     * Get number of items to invoice that don't have financing./
     * @return int
     */
    public function getInvoiceableProductsAttribute(): int
    {
        $count = 0;
        foreach ($this->items as $item)
        {
            if ($item->item && $item->item->type == BillItemType::PRODUCT->value)
            {
                if ($item->payments == 0) $count++;
            }
        }
        return $count;
    }

    /**
     * Get MRR For Quote
     * @return int
     */
    public function getMrrAttribute(): int
    {
        $total = 0;
        foreach ($this->services as $service)
        {
            $total += ($service->price * $service->qty) + $service->addonTotal;
        }
        foreach ($this->products as $product)
        {
            if (!$product->frequency || !$product->payments) continue; // Only count financed
            $total += $product->frequency->splitTotal($product->qty * $product->price, $product->payments);
        }
        return (int) bcmul($total, 1);
    }

    /**
     * Get discovery link
     * @return string|null
     */
    public function getDiscoveryBoardAttribute(): ?string
    {
        if (!$this->lead) return null;
        return $this->lead->discovery_link;
    }

    /**
     * Get NRC for Quote
     * @return int
     */
    public function getNrcAttribute(): int
    {
        $total = 0;
        foreach ($this->products as $product)
        {
            if ($product->frequency && $product->payments) continue; // Don't count financed
            $total += ($product->price * $product->qty) + $product->addonTotal;
        }
        return (int) bcmul($total, 1);
    }

    /**
     * Get subtotal before tax
     * @return int
     */
    public function getSubtotalAttribute(): int
    {
        return $this->mrr + $this->nrc;
    }

    /**
     * Get the total for the quote.
     * @return int
     */
    public function getTotalAttribute(): int
    {
        return $this->mrr + $this->nrc + $this->tax;
    }

    /**
     * Get a basic cost analysis for the quote.
     * @return object
     */
    public function getAnalysisAttribute(): object
    {
        return AnalysisEngine::byQuote($this);
    }

    /**
     * Get how old this quote is
     * @return int
     */
    public function getAgeAttribute(): int
    {
        return $this->created_at->diffInDays();
    }

    /**
     * Get Opex for a Quote (for reporting)
     * @return int
     */
    public function getOpexAttribute(): int
    {
        return $this->getAnalysisAttribute()->opexSolo;
    }

    /**
     * Get quote capex
     * @return int
     */
    public function getCapexAttribute(): int
    {
        return $this->getAnalysisAttribute()->capex;
    }

    /**
     * Get Commissionable amount based on user logged in.
     * @return int
     */
    public function getCommissionableAttribute(): int
    {
        // Use the analysis engine and don't try to figure this out by hand here.
        $obj = AnalysisEngine::byQuote($this);
        if (user()->agent_comm_mrc)
        {
            return $obj->monthlyCommission;
        }
        else return $obj->agentSpiff;
    }

    /**
     * Get the tax for a quote.
     * @return void
     */
    public function calculateTax(): void
    {
        try
        {
            $tax = Finance::taxByQuote($this);
            $this->update(['tax' => $tax]);
            return;
        } catch (Exception)
        {
            // No taxation available - continue;
        }
        $total = 0;
        $rate = 0;
        if ($this->lead) $rate = TaxLocation::findByLocation($this->lead->state);
        if ($this->account) $rate = TaxLocation::findByLocation($this->account->state);
        if ($this->lead && !$this->lead->taxable)
        {
            $this->update(['tax' => 0]);
            return;
        }
        if ($this->account && !$this->account->taxable)
        {
            $this->update(['tax' => 0]);
            return;
        }
        if (!$rate) return;
        foreach ($this->items as $item)
        {
            if (!$item->item || !$item->item->taxable) continue;
            $itemTotal = bcmul($item->price * $item->qty, 1);
            $tax = bcmul($itemTotal * ($rate / 100), 1);
            $total += $tax;
        }
        $this->update(['tax' => $total]);
    }

    /**
     * Determine the discount on an entire quote based on the
     * pricing of each item individually if we have the setting enabled.
     * @return int
     */
    public function getDiscountAttribute(): int
    {
        if (setting('quotes.showDiscount') == 'None') return 0;
        $totalCatalog = 0;
        $totalQuoted = 0;
        foreach ($this->items as $item)
        {
            $totalCatalog += bcmul($item->getCatalogPrice() * $item->qty, 1);
            $totalQuoted += bcmul($item->price * $item->qty, 1);
        }
        return $totalCatalog - $totalQuoted;
    }

    /**
     * Create a small card for rendering the activity widget
     * with some details on the quote.
     * @return string
     */
    public function getActivityWidgetAttribute(): string
    {
        return "MRR: $" .
            moneyFormat($this->mrr) .
            " / NRC: $" .
            moneyFormat($this->nrc);
    }

    /**
     * Returns a badge showing how far over or under our margin is compared to our target.
     * @return string
     */
    public function getMarginBadgeAttribute(): string
    {
        $target = setting('quotes.margin');
        $act = $this->analysis->margin;
        $color = $act < $target ? "bg-danger" : "bg-success";
        $variation = $act / $target * 100;
        $symbol = $color == 'bg-success' ? "+" : null;
        $variation -= 100;
        $variation = round($variation, 2);
        return "<span class='badge $color'>{$symbol}{$variation}%</span>";

        // Now we want to show + % over target if over or - % under if under.
    }

    /**
     * Return a streamed PDF.
     * @param bool $save
     * @return mixed
     */
    public function simplePDF(bool $save = false): mixed
    {
        $pdf = new MakePDF();
        $pdf->setName("Quote-$this->id.pdf");
        $data = view("pdf.quote")->with('quote', $this)->render();
        if (!$save)
        {
            return $pdf->streamFromData($data);
        }
        else return storage_path() . "/" . $pdf->saveFromData($data);
    }

    /**
     * Return a streamed PDF of the MSA.
     * @param bool $save
     * @return mixed
     */
    public function simpleMSA(bool $save = false): mixed
    {
        $pdf = new MakePDF();
        $pdf->setName("Contract-$this->id.pdf");
        $data = view("pdf.contract")->with('quote', $this)->render();
        if (!$save)
        {
            return $pdf->streamFromData($data);
        }
        else return storage_path() . "/" . $pdf->saveFromData($data);
    }

    /**
     * Send quote to lead/account.
     */
    public function send(): void
    {
        $this->update(['presentable' => true]);
        $amt = "$" . moneyFormat($this->total);
        $view = $this->lead ? "lead.quote" : "account.quote";
        if ($this->lead)
        {
            template($view, null, [$this, $this->lead->agent], [$this->simplePDF(true)], $this->lead->email,
                $this->lead->contact);
            template($view, $this->lead->agent, [$this, $this->lead->agent], [$this->simplePDF(true)]);

            $this->lead->update(['status' => LeadStatus::QuoteSent->value]);
            sysact(ActivityType::LeadQuote, $this->id, "sent a quote ($amt) ", $this->getActivityWidgetAttribute());
        } // if lead
        else
        {
            if ($this->coterm)
            {
                // Different email for coterming.
                template('account.coterm', $this->account->admin, [$this, $this->account->agent],
                    [$this->simplePDF(true)]);
                sysact(ActivityType::AccountQuote, $this->id,
                    "sent a cotermed quote ($amt)", $this->getActivityWidgetAttribute());
                return;

            }
            // If this is an established account, send to the primary account holder.
            template($view, $this->account->admin, [$this, $this->account->agent], [$this->simplePDF(true)]);
            sysact(ActivityType::AccountQuote, $this->id, "sent a new quote ($amt) ",
                $this->getActivityWidgetAttribute());
        }
    }

    /**
     * Send fully signed MSA with TOS included
     * @return void
     */
    public function sendSigned(): void
    {
        template('quote.signed', $this->account->admin, [$this], [$this->simpleMSA(true)]);
    }

    /**
     * Determine if we can edit a quote.
     * @return bool
     */
    public function getEditableAttribute(): bool
    {
        if ($this->activated_on) return false;
        if ($this->archived) return false;
        return true;
    }

    /**
     * Execute and terminate previous contract and renew with this one.
     * @return void
     */
    public function executeCoterm(): void
    {
        // Step 1: Remove all CONTRACTED services that are attached to the src quote
        $this->account->items()->where('quote_id', $this->coterm->id)->delete();
        // Step 2: Update our current quote (this) with the signature, and term and expiration
        $this->update([
            'contract_expires' => $this->coterm->contract_expires,
            'activated_on'     => now(),
            'term'             => $this->coterm->term,
            'signature'        => $this->coterm->signature,
            'active'           => false,
            'status'           => 'Executed',
            'contract_name'    => $this->coterm->contract_name,
            'contract_ip'      => $this->coterm->contract_ip
        ]);
        // Step 3: Terminate/End the SRC contract.
        $this->coterm->update([
            'status'           => 'Terminated',
            'contract_expires' => now(),
            'active'           => false        // Just in case?
        ]);
        // Step 4: Take our new services and add to account services.
        foreach ($this->services as $item)
        {
            if ($item->item)
            {
                $this->account->items()->create([
                    'bill_item_id' => $item->item->id,
                    'description'  => $item->item->description,
                    'price'        => $item->price,
                    'qty'          => $item->qty,
                    'notes'        => $item->notes,
                    'quote_id'     => $this->id,
                    'meta'         => $item->meta
                ]);
            }
        }

        // Step 5: Create an Invoice for any one time items that were added to this quote.
        if (count($this->products))
        {
            $invoice = $this->account->invoices()->create([
                'due_on' => now()->addDays($this->account->net_terms),
                'status' => InvoiceStatus::DRAFT
            ]);
            foreach ($this->products as $item)
            {
                if (!$item->item) continue; // Service was deleted.
                $invoice->items()->create([
                    'bill_item_id' => $item->bill_item_id,
                    'code'         => $item->item->code,
                    'name'         => $item->item->name,
                    'description'  => $item->description . " " . $item->notes,
                    'price'        => $item->price,
                    'qty'          => $item->qty,
                    'meta'         => $item->meta
                ]);
            }
            $invoice->refresh();
            $invoice->send();
        }

        // Step 6: Notify Customer of Cotermed
        template('account.cotermexe', $this->account->admin, [$this], $this->simplePDF(true));
    }


    /**
     * Get Company Name Attribute
     * @return string
     */
    public function getCompanyNameAttribute(): string
    {
        return $this->lead ? $this->lead->company : $this->account->name;
    }

    /**
     * Get Direct Link to Quote to Execute
     * @return string
     */
    public function getExecuteLinkAttribute(): string
    {
        if ($this->lead)
        {
            return sprintf("%s/shop/presales/%s/%s",
                setting('brand.url'), $this->lead->hash, $this->hash);
        }
        else
        {
            return setting('brand.url');
        }
    }

    /**
     * Return the properties for the bar. Size, color, etc.
     * @return object
     */
    public function getBarAttribute(): object
    {
        $target = (int)setting('quotes.margin');
        $is = $this->analysis->margin;
        $toTarget = ($is / $target * 100);
        if ($toTarget < 75)
        {
            $color = 'bg-danger';
        }
        elseif ($toTarget >= 75 && $toTarget < $target)
        {
            $color = 'bg-warning';
        }
        else $color = 'bg-success';
        return (object)[
            'color' => $color,
            'width' => $toTarget
        ];

    }

    /**
     * Reassign and Execute a quote
     * @param Account $account
     * @param string  $name
     * @param string  $signature
     * @return void
     */
    public function reassignExecuted(Account $account, string $name, string $signature): void
    {
        // Our new sigpad writes base64 encoded PNGs so we need to convert this into a file.
        $lo = new LoFileHandler();
        $x = explode(",", $signature);
        $based = $x[1]; // everything after the base64,is the actual encoded part.
        $file = $lo->create($this->id . "-signature.png", FileType::Image, $this->id, $based, 'image/png');
        $this->update([
            'activated_on'     => now(),
            'contract_name'    => $name,
            'contract_ip'      => app('request')->ip(),
            'signature_id'     => $file->id,
            'contract_expires' => $this->term ? now()->addMonths($this->term) : null,
            'status'           => 'Executed',
            'archived'         => 1,
            'account_id'       => $account->id
        ]);
    }

    /**
     * Get MSA Start Date
     * @return string
     */
    public function getMsaStartAttribute(): string
    {
        if ($this->activated_on) return $this->activated_on->format("F d, Y");
        return now()->format("F d, Y");
    }

    /**
     * Get MSA End Date.
     * @return string
     */
    public function getMsaEndAttribute(): string
    {
        if ($this->activated_on) return $this->activated_on->addMonths($this->term)->format("F d, Y");
        return now()->addMonths($this->term)->format("F d, Y");
    }

    /**
     * Get markdown enabled MSA
     * @return string
     */
    public function getMsaContentAttribute(): string
    {
        $content = setting('quotes.msa');
        $models = [$this];
        $s = new STemplate(ident: $content, models: $models);
        return $s->contentBody;
    }

    /**
     * Get a list of TOS (ids only) from the quote
     * @return array
     */
    public function getTOSArray(): array
    {
        $all = [];
        foreach ($this->items as $item)
        {
            if ($item->item->terms)
            {
                if (!in_array($item->item->terms->id, $all))
                {
                    $all[] = $item->item->terms->id;
                }
            }
        }
        return $all;
    }

    /**
     * Get a selectable list of term options.
     * @return array
     */
    static public function getTermSelectable(): array
    {
        $x = explode(",", setting('quotes.terms'));
        $data = [];
        $data[0] = "Month-To-Month";
        foreach ($x as $term)
        {
            $term = (int)trim($term);
            $data[$term] = "$term Months";
        }
        return $data;
    }

    /**
     * After adding or removing a quote item, we need to fill in
     * any holes and reorder to allow for save moving.
     * @return void
     */
    public function reord(): void
    {
        // In this case, an item has been added or deleted. Just make sure we don't have weird holes in ord.
        // First reorder services.
        // return;
        $this->refresh();
        $sPos = 0;
        $pPos = 0;
        foreach ($this->services()->orderBy('ord')->get() as $item)
        {
            $sPos++;
            $item->update(['ord' => $sPos]);
        }
        foreach ($this->products()->orderBy('ord')->get() as $item)
        {
            $pPos++;
            $item->update(['ord' => $pPos]);
        }
    }

}
