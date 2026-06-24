<?php

namespace App\Http\Controllers;

use App\Models\CustomersMaster;
use App\Models\ProductQuestion;
use App\Models\ProductQuestionVote;
use App\Models\ProductReview;
use App\Models\ProductReviewVote;
use App\Models\Products;
use App\Support\Reviews\ProductEngagementRules;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ProductEngagementController extends Controller
{
    public function reviews(Products $product)
    {
        $reviews = ProductReview::query()
            ->with([
                'customer:id,Customer_Full_Name',
                'replies' => fn ($query) => $query->where('Status', 'approved')->oldest(),
            ])
            ->where('Products_Id', $product->id)
            ->where('Status', ProductEngagementRules::STATUS_APPROVED)
            ->latest()
            ->get();

        return response()->json([
            'summary' => ProductEngagementRules::ratingSummary($reviews),
            'data' => $reviews,
        ]);
    }

    public function storeReview(Request $request, Products $product)
    {
        $customer = $this->customer();

        $validated = $request->validate([
            'rating' => ['required', 'integer', 'min:1', 'max:5'],
            'title' => ['nullable', 'string', 'max:160'],
            'body' => ['required', 'string', 'min:5', 'max:4000'],
        ]);

        $exists = ProductReview::query()
            ->where('Products_Id', $product->id)
            ->where('Customers_Id', $customer->id)
            ->whereIn('Status', ['pending', 'approved', 'reported'])
            ->exists();

        if ($exists) {
            throw ValidationException::withMessages([
                'product' => ['You have already submitted a review for this product.'],
            ]);
        }

        $purchase = $this->verifiedPurchase($product, $customer);

        $review = ProductReview::create([
            'Products_Id' => $product->id,
            'Customers_Id' => $customer->id,
            'Orders_Placed_Id' => $purchase['orders_placed_id'],
            'Orders_Placed_Details_Id' => $purchase['orders_placed_details_id'],
            'Rating' => (int) $validated['rating'],
            'Title' => $validated['title'] ?? null,
            'Body' => $validated['body'],
            'Status' => 'pending',
            'Verified_Purchase' => $purchase['verified'],
        ]);

        return response()->json([
            'message' => 'Review submitted for moderation.',
            'data' => $review,
        ], 201);
    }

    public function helpfulReview(ProductReview $review)
    {
        $customer = $this->customer();
        $this->recordVote($review, ProductReviewVote::class, 'Product_Review_Id', $customer, 'helpful');

        return response()->json(['data' => $review->fresh()]);
    }

    public function reportReview(Request $request, ProductReview $review)
    {
        $customer = $this->customer();
        $reason = $request->validate(['reason' => ['nullable', 'string', 'max:500']])['reason'] ?? null;
        $this->recordVote($review, ProductReviewVote::class, 'Product_Review_Id', $customer, 'report', $reason);

        return response()->json(['data' => $review->fresh()]);
    }

    public function questions(Products $product)
    {
        $questions = ProductQuestion::query()
            ->with([
                'customer:id,Customer_Full_Name',
                'answers' => fn ($query) => $query->where('Status', 'approved')->oldest(),
            ])
            ->where('Products_Id', $product->id)
            ->where('Status', ProductEngagementRules::STATUS_APPROVED)
            ->latest()
            ->get();

        return response()->json(['data' => $questions]);
    }

    public function storeQuestion(Request $request, Products $product)
    {
        $customer = $this->customer();

        $validated = $request->validate([
            'question' => ['required', 'string', 'min:5', 'max:2000'],
        ]);

        $question = ProductQuestion::create([
            'Products_Id' => $product->id,
            'Customers_Id' => $customer->id,
            'Question' => $validated['question'],
            'Status' => 'pending',
        ]);

        return response()->json([
            'message' => 'Question submitted for moderation.',
            'data' => $question,
        ], 201);
    }

    public function helpfulQuestion(ProductQuestion $question)
    {
        $customer = $this->customer();
        $this->recordVote($question, ProductQuestionVote::class, 'Product_Question_Id', $customer, 'helpful');

        return response()->json(['data' => $question->fresh()]);
    }

    public function reportQuestion(Request $request, ProductQuestion $question)
    {
        $customer = $this->customer();
        $reason = $request->validate(['reason' => ['nullable', 'string', 'max:500']])['reason'] ?? null;
        $this->recordVote($question, ProductQuestionVote::class, 'Product_Question_Id', $customer, 'report', $reason);

        return response()->json(['data' => $question->fresh()]);
    }

    private function customer(): CustomersMaster
    {
        $user = Auth::guard('api')->user();

        abort_unless($user, 401, 'Unauthenticated');

        return $user->customerOrCreate();
    }

    /**
     * @return array{verified: bool, orders_placed_id: int|null, orders_placed_details_id: int|null}
     */
    private function verifiedPurchase(Products $product, CustomersMaster $customer): array
    {
        $lines = DB::table('Orders_Placed_Details_T as d')
            ->join('Orders_Placed_T as o', 'o.id', '=', 'd.Orders_Placed_Id')
            ->join('Customers_Contact_T as c', 'c.id', '=', 'o.Customers_Contacts_Id')
            ->where('d.Products_Id', $product->id)
            ->where('c.Customers_Contact_Id', $customer->id)
            ->select([
                'd.Products_Id',
                DB::raw('c.Customers_Contact_Id as Customers_Id'),
                DB::raw('o.Status as Order_Status'),
                DB::raw('d.Status as Detail_Status'),
                DB::raw('o.id as Orders_Placed_Id'),
                DB::raw('d.id as Orders_Placed_Details_Id'),
            ])
            ->latest('o.created_at')
            ->get()
            ->map(fn ($line) => (array) $line)
            ->all();

        return ProductEngagementRules::verifiedPurchaseFromLines($lines, $product->id, $customer->id);
    }

    /**
     * @param class-string<Model> $voteClass
     */
    private function recordVote(Model $subject, string $voteClass, string $foreignKey, CustomersMaster $customer, string $type, ?string $reason = null): void
    {
        $vote = $voteClass::firstOrCreate([
            $foreignKey => $subject->id,
            'Customers_Id' => $customer->id,
            'Vote_Type' => $type,
        ], [
            'Reason' => $reason,
        ]);

        if (!$vote->wasRecentlyCreated && $reason !== null) {
            $vote->forceFill(['Reason' => $reason])->save();
        }

        $helpful = $voteClass::query()->where($foreignKey, $subject->id)->where('Vote_Type', 'helpful')->count();
        $reports = $voteClass::query()->where($foreignKey, $subject->id)->where('Vote_Type', 'report')->count();

        $payload = [
            'Helpful_Count' => $helpful,
            'Report_Count' => $reports,
        ];

        if ($type === 'report' && $reports > 0) {
            $payload['Status'] = 'reported';
        }

        $subject->forceFill($payload)->save();
    }
}
